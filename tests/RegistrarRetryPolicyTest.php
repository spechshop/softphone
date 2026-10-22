<?php

// Run with the project's PHP/Swoole runtime:
// php tests/RegistrarRetryPolicyTest.php

require __DIR__ . '/../libspech/plugins/autoloader.php';
require __DIR__ . '/../plugins/Utils/helpers/Registrar.php';

use helpers\utils\Registrar;

function retryAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function failedAttempt(string $fp, array $account, string $reason): void
{
    $method = new ReflectionMethod(Registrar::class, 'recordFailure');
    $method->invoke(null, $fp, $account['sipUser'], $account['sipServer'],
        $account['sipDomain'], $account, $reason);
}

Registrar::init();
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
retryAssert($row['next_retry_at'] > time(), 'first timeout must delay retries');
retryAssert(!Registrar::automaticAttemptAllowed('timeout-a', $account), 'retry must not run on every tick');
failedAttempt('timeout-a', $account, 'timeout');
retryAssert(Registrar::$states->get('timeout-a')['failures'] === 2, 'second failure counted');
failedAttempt('timeout-a', $account, 'timeout');
retryAssert(Registrar::$states->get('timeout-a')['status'] === 'paused', 'third failure must suspend automatic retries');
retryAssert(!Registrar::automaticAttemptAllowed('timeout-a', $account), 'suspended account must not retry');
retryAssert(Registrar::automaticAttemptAllowed('timeout-a', [...$account, 'sipPass' => 'changed-secret']),
    'credential changes must reopen the circuit');

failedAttempt('auth-failure', $account, 'authentication_failed');
retryAssert(Registrar::$states->get('auth-failure')['status'] === 'paused',
    'invalid credentials must suspend immediately');
retryAssert(!Registrar::automaticAttemptAllowed('auth-failure', $account),
    'invalid credentials must never spin');
failedAttempt('auth-failure', [...$account, 'sipPass' => 'updated-secret'], 'timeout');
retryAssert(Registrar::$states->get('auth-failure')['failures'] === 1,
    'changed configuration must reset failure counter');
retryAssert(Registrar::$states->get('auth-failure')['status'] === 'failed',
    'changed configuration should have fresh transient retry budget');

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
