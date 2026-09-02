<?php

require __DIR__ . '/../libspech/plugins/autoloader.php';
require __DIR__ . '/../plugins/Utils/helpers/DataPath.php';
require __DIR__ . '/../plugins/Utils/helpers/AccountIdentity.php';
require __DIR__ . '/../plugins/Utils/helpers/WebPushHelper.php';

use helpers\utils\DataPath;
use helpers\utils\WebPushHelper;

function storageExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function storageWrite(string $file, array $data): void
{
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0700, true);
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$root = sys_get_temp_dir() . '/spech-storage-test-' . bin2hex(random_bytes(6));
$configured = $root . '/configured-state';
putenv('SPECH_DATA_DIR=' . $configured);
DataPath::setDir(null);

$target = DataPath::file('push_subscriptions.json');
storageExpect($target === $configured . '/push_subscriptions.json', 'SPECH_DATA_DIR não foi respeitado');
storageExpect(is_dir($configured) && is_writable($configured), 'diretório persistente não foi criado sem sudo');
storageExpect(!str_starts_with($target, '/tmp/data/spechphone/'), '/tmp legado virou storage padrão');

$sub = static fn(string $endpoint, string $fp, string $marker): array => [
    'endpoint' => $endpoint,
    'keys' => ['p256dh' => 'public-' . $marker, 'auth' => 'auth-' . $marker],
    'fp' => $fp,
];

$legacyData = $root . '/legacy-data/push_subscriptions.json';
$legacyTmp = $root . '/legacy-tmp/push_subscriptions.json';
storageWrite($target, [
    'fp-a' => ['old-key' => $sub('https://push.example/shared-a', 'fp-a', 'new-path')],
]);
storageWrite($legacyData, [
    'lotus' => [
        'duplicate-a' => $sub('https://push.example/shared-a', 'fp-a', 'data-duplicate'),
        'only-b' => $sub('https://push.example/only-b', 'fp-b', 'data-b'),
    ],
]);
storageWrite($legacyTmp, [
    'lotus' => [
        'duplicate-b' => $sub('https://push.example/only-b', 'fp-b', 'tmp-duplicate'),
        'only-a' => $sub('https://push.example/only-a', 'fp-a', 'tmp-a'),
    ],
]);

$migration = WebPushHelper::migrateStorage([$legacyData, $legacyTmp]);
storageExpect($migration['sources'] === 2 && $migration['migrated'] === 2, 'contagem da migração /data + /tmp incorreta');
$stored = json_decode((string)file_get_contents($target), true);
storageExpect(count($stored['fp-a'] ?? []) === 2, 'subscriptions fp-a não foram mescladas/deduplicadas');
storageExpect(count($stored['fp-b'] ?? []) === 1, 'subscriptions fp-b não foram preservadas/deduplicadas');
storageExpect(($stored['fp-a'][hash('sha256', 'https://push.example/shared-a')]['keys']['auth'] ?? '') === 'auth-new-path', 'prioridade do novo path não venceu legado');
storageExpect(!isset($stored['lotus']), 'migration voltou a usar sipUser como chave');
storageExpect(is_file($legacyData) && is_file($legacyTmp), 'arquivo legado foi removido antes/depois da confirmação');

WebPushHelper::saveSubscription('fp-a', $sub('https://push.example/restart', 'fp-a', 'restart'));
storageExpect(WebPushHelper::subscriptionCountForAccount('fp-a') === 3, 'subscription não ficou disponível após releitura');
@unlink($legacyData);
@unlink($legacyTmp);
storageExpect(WebPushHelper::subscriptionCountForAccount('fp-a') === 3, 'limpeza de tmp/legados afetou novo storage');
storageExpect((fileperms($target) & 0777) === 0600, 'arquivo de subscriptions não está restrito a 0600');

$legacyVault = $root . '/legacy-vault/devices.vault';
storageWrite($legacyVault, ['encrypted-fixture' => 'opaque-bytes']);
$targetVault = DataPath::file('devices.vault');
$vaultSource = DataPath::migrateFirstExisting('devices.vault', [$legacyVault]);
storageExpect($vaultSource === $legacyVault && hash_file('sha256', $targetVault) === hash_file('sha256', $legacyVault), 'vault não foi copiado/verificado');
storageExpect(is_file($legacyVault), 'vault legado foi removido');

putenv('SPECH_DATA_DIR');
$xdgRoot = $root . '/xdg';
putenv('XDG_STATE_HOME=' . $xdgRoot);
storageExpect(DataPath::dir() === $xdgRoot . '/spechphone', 'fallback XDG_STATE_HOME não foi respeitado');
putenv('XDG_STATE_HOME');
$fakeHome = $root . '/home';
putenv('HOME=' . $fakeHome);
storageExpect(DataPath::dir() === $fakeHome . '/.local/state/spechphone', 'fallback ~/.local/state não foi respeitado');

// Best-effort cleanup of this test's explicit, validated subtree only.
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($iterator as $entry) {
    if ($entry->isDir()) @rmdir($entry->getPathname());
    else @unlink($entry->getPathname());
}
@rmdir($root);

echo "OK: data dir sem sudo, persistência, prioridade e migração /data + /tmp isolada por accountId.\n";
