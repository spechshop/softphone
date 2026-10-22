<?php

// Run with the project's PHP/Swoole runtime:
// php tests/RegistrarRetryPolicyTest.php

require __DIR__ . '/../libspech/plugins/autoloader.php';
require __DIR__ . '/../plugins/Utils/helpers/DataPath.php';
require __DIR__ . '/../plugins/Utils/helpers/CallState.php';
require __DIR__ . '/../plugins/Utils/helpers/Registrar.php';

use helpers\utils\Registrar;
use helpers\utils\DataPath;

function retryAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function failedAttempt(string $fp, array $account, string $reason, ?int $retryAfter = null, ?int $code = null): void
{
    $method = new ReflectionMethod(Registrar::class, 'recordFailure');
    $method->invoke(null, $fp, $account['sipUser'], $account['sipServer'],
        $account['sipDomain'], $account, $reason, $retryAfter, $code);
}

if (($argv[1] ?? '') === 'restart-check') {
    DataPath::setDir($argv[2]);
    Registrar::init();
    $restartAccount = json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR);
    echo Registrar::automaticAttemptAllowed('auth-failure', $restartAccount) ? "allowed\n" : "suspended\n";
    exit(0);
}

Registrar::init();
$dataDir = sys_get_temp_dir() . '/spechphone-retry-test-' . bin2hex(random_bytes(5));
DataPath::setDir($dataDir);
$account = [
    'sipServer' => '127.0.0.1:5060',
    'sipDomain' => 'example.test',
    'sipUser' => '1000',
    'sipPass' => 'test-only-secret',
];

retryAssert(Registrar::automaticAttemptAllowed('timeout-a', $account), 'new account should register');
failedAttempt('timeout-a', $account, 'timeout');
$row = Registrar::$states->get('timeout-a');
retryAssert($row['failures'] === 1 && $row['status'] === 'failed', 'first timeout should back off');
retryAssert($row['next_retry_at'] - $row['last_attempt_at'] === 60, 'first failure must wait exactly 60 seconds');
retryAssert(!Registrar::automaticAttemptAllowed('timeout-a', $account), 'retry must not run on every tick');
failedAttempt('timeout-a', $account, 'timeout');
retryAssert(Registrar::$states->get('timeout-a')['failures'] === 2, 'second failure counted');
retryAssert(Registrar::$states->get('timeout-a')['next_retry_at'] - Registrar::$states->get('timeout-a')['last_attempt_at'] === 120,
    'second failure must wait exactly 120 seconds');
failedAttempt('timeout-a', $account, 'timeout');
retryAssert(Registrar::$states->get('timeout-a')['status'] === 'paused', 'third failure must suspend automatic retries');
retryAssert(!Registrar::automaticAttemptAllowed('timeout-a', $account), 'suspended account must not retry');
$pauseFile = DataPath::file('sip_registration_pauses.json');
$pauseData = json_decode((string)file_get_contents($pauseFile), true, 512, JSON_THROW_ON_ERROR);
$pauseData['timeout-a']['expires_at'] = time() - 1;
file_put_contents($pauseFile, json_encode($pauseData, JSON_THROW_ON_ERROR));
retryAssert(Registrar::automaticAttemptAllowed('timeout-a', $account),
    'temporary exhaustion must permit one controlled recovery probe after pause expiry');
retryAssert(Registrar::automaticAttemptAllowed('timeout-a', [...$account, 'sipPass' => 'changed-secret']),
    'credential changes must reopen the circuit');

failedAttempt('auth-failure', $account, 'authentication_failed');
retryAssert(Registrar::$states->get('auth-failure')['status'] === 'paused',
    'invalid credentials must suspend immediately');
retryAssert(!Registrar::automaticAttemptAllowed('auth-failure', $account),
    'invalid credentials must never spin');
$pipes = [];
$child = proc_open([PHP_BINARY, __FILE__, 'restart-check', $dataDir,
    json_encode($account, JSON_THROW_ON_ERROR)],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
retryAssert(is_resource($child), 'could not launch process restart probe');
fclose($pipes[0]);
$childOutput = trim((string)stream_get_contents($pipes[1]));
$childError = (string)stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
retryAssert(proc_close($child) === 0, 'process restart probe failed: ' . $childError);
retryAssert($childOutput === 'suspended', 'fresh process must load persistent auth suspension');
Registrar::$states->del('auth-failure'); // Simulate process restart losing its Swoole table.
retryAssert(!Registrar::automaticAttemptAllowed('auth-failure', $account),
    'authentication suspension must survive process restart');
failedAttempt('auth-failure', [...$account, 'sipPass' => 'updated-secret'], 'timeout');
retryAssert(Registrar::$states->get('auth-failure')['failures'] === 1,
    'changed configuration must reset failure counter');
retryAssert(Registrar::$states->get('auth-failure')['status'] === 'failed',
    'changed configuration should have fresh transient retry budget');

failedAttempt('rate-limit', $account, 'sip_error', 90, 429);
retryAssert(Registrar::$states->get('rate-limit')['next_retry_at'] - Registrar::$states->get('rate-limit')['last_attempt_at'] === 90,
    '429 Retry-After must override default retry delay');

$source = file_get_contents(__DIR__ . '/../plugins/Utils/helpers/Registrar.php');
retryAssert(!str_contains($source, 'WebPushHelper::notifyUser'),
    'SIP registration failures must not create Web Push messages');
$explicitSource = file_get_contents(__DIR__ . '/../plugins/Message/handlers/register.php');
retryAssert(str_contains($explicitSource, '$data, true)'),
    'manual register must override automatic suspension');
$saveSource = file_get_contents(__DIR__ . '/../plugins/Message/handlers/saveConfig.php');
retryAssert(str_contains($saveSource, '$data, true)'),
    'manual configuration save must override suspension');
echo "OK: retry backoff, suspension, config changes, manual actions, no failure push.\n";
