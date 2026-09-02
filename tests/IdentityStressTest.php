<?php

require __DIR__ . '/../libspech/plugins/autoloader.php';
require __DIR__ . '/../plugins/Utils/helpers/AccountIdentity.php';
require __DIR__ . '/../plugins/Utils/helpers/WebPushHelper.php';
require __DIR__ . '/../plugins/Utils/messages/messageStore.php';

use helpers\utils\AccountIdentity;
use helpers\utils\WebPushHelper;
use plugins\Utils\messages\messageStore;

function stressExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$accounts = [];
for ($i = 0; $i < 20; $i++) {
    $fp = "stress-fp-{$i}";
    $accounts[$fp] = AccountIdentity::fromData($fp, [
        'sipUser' => 'shared-' . ($i % 4),
        'sipDomain' => "provider-{$i}.test",
        'sipServer' => "registrar-{$i}.test:5060",
    ]);
}

$connections = [];
for ($event = 0; $event < 500; $event++) {
    $i = $event % 20;
    $fp = "stress-fp-{$i}";
    $account = $accounts[$fp];
    $method = $event % 2 === 0 ? 'INVITE' : 'MESSAGE';

    $domainRoute = AccountIdentity::resolve($account['sipUser'], $account['sipDomain'], '', $accounts);
    stressExpect($domainRoute['accountId'] === $fp, "{$method} domínio cruzou conta no evento {$event}");
    $contactRoute = AccountIdentity::resolve('rewritten-user', '147.93.67.151', '', $accounts, AccountIdentity::contactUser($fp));
    stressExpect($contactRoute['accountId'] === $fp, "{$method} Contact cruzou conta no evento {$event}");
    $ambiguous = AccountIdentity::resolve($account['sipUser'], '147.93.67.151', '147.93.67.151', $accounts);
    stressExpect($ambiguous['status'] === 'ambiguous' && $ambiguous['accountId'] === null, "{$method} username repetido não foi bloqueado");

    // Interleaved reconnect/disconnect bookkeeping remains keyed by accountId.
    $connections[$fp][] = 1000 + $event;
    stressExpect(messageStore::connectionFdsForAccount($connections, $fp) === array_map('intval', $connections[$fp]), 'reconnect cruzou FD');
    array_pop($connections[$fp]);
    if (!$connections[$fp]) unset($connections[$fp]);
}
stressExpect($connections === [], 'disconnect stress não retornou connections ao baseline');

$pushFile = sys_get_temp_dir() . '/spech-push-stress-' . bin2hex(random_bytes(6)) . '.json';
WebPushHelper::setFile($pushFile);
for ($i = 0; $i < 20; $i++) {
    $fp = "stress-fp-{$i}";
    WebPushHelper::saveSubscription($fp, [
        'endpoint' => "https://push.example/{$fp}",
        'keys' => ['p256dh' => "public-{$i}", 'auth' => "auth-{$i}"],
    ]);
}
for ($i = 0; $i < 20; $i++) {
    stressExpect(WebPushHelper::subscriptionCountForAccount("stress-fp-{$i}") === 1, "push da conta {$i} vazou/duplicou");
}
@unlink($pushFile);
@unlink($pushFile . '.lock');

echo "OK: 20 contas, usernames repetidos e 500 eventos INVITE/MESSAGE/reconnect/disconnect/push sem cruzamento.\n";
