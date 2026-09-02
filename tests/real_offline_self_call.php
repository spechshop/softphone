<?php

/**
 * Real-provider probe for an already running server.php.
 *
 * It originates an authenticated self-call from a separate ephemeral UDP
 * socket. It deliberately does not REGISTER and does not open WebSocket, so
 * the provider must route the inbound leg to server.php's existing opaque
 * Contact binding. A final 480 is expected when the page remains closed.
 */

require __DIR__ . '/../libspech/plugins/autoloader.php';
foreach ([
    'SipRegisterManager.php', 'SdpHelper.php', 'SipTransactionManager.php', 'SipDialog.php',
    'SipDigestAuth.php', 'PhoneController.php', 'OutboundMediaSession.php', 'OutboundCall.php',
] as $helper) require_once __DIR__ . '/../plugins/Utils/helpers/' . $helper;

use helpers\utils\OutboundCall;
use helpers\utils\PhoneController;
use libspech\Sip\sip;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;

foreach (file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(ltrim($line), '#') || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    putenv(trim($key) . '=' . trim(trim($value), "'\""));
}

$host = trim((string)getenv('SIP_HOST'));
$port = (int)(getenv('SIP_PORT') ?: 5060);
$username = trim((string)getenv('SIP_USERNAME'));
$password = (string)getenv('SIP_PASSWORD');
if ($host === '' || $username === '' || $password === '') exit(2);

final class OfflineProbeTransport
{
    public Socket $socket;
    public int $packets = 0;

    public function __construct()
    {
        $this->socket = new Socket(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$this->socket->bind('0.0.0.0', 0)) throw new RuntimeException('ephemeral_udp_bind_failed');
    }

    public function sendto(string $ip, int $port, string $packet): int|false
    {
        $this->packets++;
        return $this->socket->sendto($ip, $port, $packet);
    }
}

$exit = 1;
Coroutine\run(function () use ($host, $port, $username, $password, &$exit): void {
    $transport = new OfflineProbeTransport();
    $controller = PhoneController::resetForTests($transport);
    $running = true;
    go(function () use ($transport, $controller, &$running): void {
        while ($running) {
            $peer = [];
            $raw = $transport->socket->recvfrom($peer, 0.25);
            if (!is_string($raw) || $raw === '') continue;
            $controller->handlePacket(sip::parse($raw), $peer);
        }
    });

    $account = [
        'sipServer' => $host . ($port === 5060 ? '' : ':' . $port),
        'sipDomain' => $host,
        'sipUser' => $username,
        'sipPass' => $password,
    ];
    $failure = ['reason' => null, 'code' => null];
    $answered = false;
    $call = $controller->createOutboundCall($account, $username, [
        'trunkCodec' => 'PCMA/8000', 'userCodec' => 'PCMA/8000',
        'noResponseTimeout' => 15.0, 'provisionalTimeout' => 45.0,
    ]);
    $call->onAnswer(function (OutboundCall $call) use (&$answered): void {
        $answered = true;
        $call->hangup();
    });
    $call->onFailed(function (OutboundCall $call, string $reason, int $code) use (&$failure): void {
        $failure = ['reason' => $reason, 'code' => $code];
    });
    $completed = $call->start();
    $running = false;
    $localPort = (int)($transport->socket->getsockname()['port'] ?? 0);
    $transport->socket->close();

    $summary = [
        'provider_probe' => true,
        'websocket_opened' => false,
        'source_port_ephemeral' => $localPort !== 0 && $localPort !== 4000,
        'packets_sent' => $transport->packets,
        'answered' => $answered,
        'completed' => $completed,
        'final_code' => $failure['code'],
        'final_reason' => $failure['reason'],
        'active_calls' => $controller->activeCallCount(),
        'pending_transactions' => $controller->pendingTransactionCount(),
    ];
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $exit = ($transport->packets >= 2 && $localPort !== 4000
        && in_array($failure['code'], [480, 486], true)
        && $controller->activeCallCount() === 0
        && $controller->pendingTransactionCount() === 0) ? 0 : 1;
});
exit($exit);
