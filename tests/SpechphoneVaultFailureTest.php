<?php
declare(strict_types=1);

// Swoole 6.2 emits this for CLI child-process shutdown after timer APIs are
// touched; production workers do not use Event::wait() during request shutdown.
error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/../plugins/Request/components/spechphoneVault.php';

function vaultExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function vaultRemoveTree(string $root): void
{
    if (!is_dir($root)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir()) @rmdir($entry->getPathname());
        else @unlink($entry->getPathname());
    }
    @rmdir($root);
}

$root = sys_get_temp_dir() . '/spech-vault-failure-test-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$key = str_repeat('ab', 32);
$vaultFile = $root . '/devices.vault';

try {
    // Normal round trip and restrictive permissions.
    $vault = new spechphoneVault($vaultFile, $key, 100);
    $vault->set('initial', ['sipUser' => 'alice']);
    $vault->flush();
    $reloaded = new spechphoneVault($vaultFile, $key, 100);
    vaultExpect($reloaded->get('initial')['sipUser'] === 'alice', 'round trip criptografado falhou');
    vaultExpect((fileperms($vaultFile) & 0777) === 0600, 'vault não ficou com permissão 0600');

    // This reproduces the production shape: several vault instances in one
    // worker used to share the same {file}.tmp.{pid} and race in their timers.
    $timerVaults = [];
    for ($i = 0; $i < 8; $i++) {
        $timerVaults[$i] = new spechphoneVault($vaultFile, $key, 100);
        $timerVaults[$i]->set('timer-' . $i, $i);
    }
    Swoole\Event::wait();
    $afterTimers = new spechphoneVault($vaultFile, $key, 100);
    for ($i = 0; $i < 8; $i++) {
        vaultExpect($afterTimers->get('timer-' . $i) === $i, "timer concorrente timer-{$i} foi perdido");
    }

    // Concurrent writers must merge their pending mutations under the file lock.
    $children = [];
    for ($i = 0; $i < 8; $i++) {
        $pid = pcntl_fork();
        vaultExpect($pid >= 0, 'pcntl_fork falhou');
        if ($pid === 0) {
            try {
                $childVault = new spechphoneVault($vaultFile, $key, 100);
                usleep(random_int(1000, 30000));
                $childVault->set('child-' . $i, $i);
                $childVault->flush();
                exit(0);
            } catch (Throwable $error) {
                fwrite(STDERR, $error->getMessage() . PHP_EOL);
                exit(1);
            }
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        vaultExpect(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, "writer {$pid} falhou");
    }
    $merged = new spechphoneVault($vaultFile, $key, 100);
    for ($i = 0; $i < 8; $i++) {
        vaultExpect($merged->get('child-' . $i) === $i, "mutação concorrente child-{$i} foi perdida");
    }

    // A target directory forces rename() to fail. Timer failures are logged,
    // retried, and contained instead of escaping and killing the Swoole worker.
    $badTarget = $root . '/cannot-replace-directory';
    mkdir($badTarget, 0700);
    $logFile = $root . '/vault-errors.log';
    $previousErrorLog = ini_get('error_log');
    ini_set('log_errors', '1');
    ini_set('error_log', $logFile);

    $failingVault = new spechphoneVault($badTarget, $key, 100);
    $failingVault->set('pending', 'must-stay-dirty');
    Swoole\Event::wait();

    ini_set('error_log', (string)$previousErrorLog);
    $reflection = new ReflectionClass($failingVault);
    $dirty = $reflection->getProperty('dirty')->getValue($failingVault);
    $timerId = $reflection->getProperty('timerId')->getValue($failingVault);
    vaultExpect($dirty === true, 'falha assíncrona descartou mutação pendente');
    vaultExpect($timerId === null, 'timer permaneceu preso após esgotar retries');
    vaultExpect(substr_count((string)file_get_contents($logFile), 'falha ao persistir') === 3, 'retries assíncronos não foram registrados');
    vaultExpect(glob($badTarget . '.tmp.*') === [], 'arquivo temporário sobrou após rename falhar');

    $thrown = false;
    try {
        $failingVault->flush();
    } catch (RuntimeException $error) {
        $thrown = str_contains($error->getMessage(), 'Não foi possível publicar');
    }
    vaultExpect($thrown, 'flush explícito não informou a falha ao chamador');

    // An unusable requested directory must really resolve into /tmp/spechphone.
    $fallbackName = 'fallback-' . bin2hex(random_bytes(6)) . '.vault';
    $fallbackVault = new spechphoneVault('/proc/spechphone-test/' . $fallbackName, $key, 100);
    $fallbackVault->set('ok', true);
    $fallbackVault->flush();
    $fallbackFile = sys_get_temp_dir() . '/spechphone/' . $fallbackName;
    vaultExpect(is_file($fallbackFile), 'fallback foi resolvido para o diretório errado');
    @unlink($fallbackFile);
    @unlink($fallbackFile . '.lock');
} finally {
    vaultRemoveTree($root);
}

echo "OK: vault atômico, concorrência, fallback e falhas assíncronas sem queda do worker.\n";
