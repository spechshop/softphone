<?php
declare(strict_types=1);

require __DIR__ . '/../libspech/plugins/autoloader.php';
require __DIR__ . '/../plugins/Utils/helpers/DataPath.php';
require __DIR__ . '/../plugins/Utils/helpers/SipRegistrationLock.php';

use helpers\utils\DataPath;
use helpers\utils\SipRegistrationLock;

function lockAssert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

if (($argv[1] ?? '') === 'child') {
    DataPath::setDir($argv[3]);
    $lock = SipRegistrationLock::acquire($argv[2]);
    if ($lock === false) { echo "blocked\n"; exit(0); }
    echo "acquired\n";
    SipRegistrationLock::release($lock);
    exit(0);
}

$dataDir = sys_get_temp_dir() . '/spech-register-lock-' . bin2hex(random_bytes(6));
DataPath::setDir($dataDir);
$fp = 'same-registration-identity';
$parentLock = SipRegistrationLock::acquire($fp);
lockAssert(is_resource($parentLock), 'parent failed to acquire account lock');

$runChild = static function () use ($fp, $dataDir): string {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, 'child', $fp, $dataDir], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('cannot start child lock probe');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) throw new RuntimeException('child lock probe failed: ' . $stderr);
    return trim((string)$stdout);
};

lockAssert($runChild() === 'blocked', 'another process acquired the same account lock concurrently');
SipRegistrationLock::release($parentLock);
lockAssert($runChild() === 'acquired', 'account lock stayed held after release');
echo "OK: account registration lock excludes a competing process and releases for recovery.\n";
