<?php

require __DIR__ . '/../libspech/plugins/autoloader.php';
require __DIR__ . '/../plugins/Utils/helpers/SipRegisterManager.php';

use helpers\utils\SipRegisterManager;
use libspech\Sip\sip;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;

foreach (file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(ltrim($line), '#') || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    putenv(trim($key) . '=' . trim(trim($value), "'\""));
}

$options = getopt('', ['invalid', 'wait-invite::']);
$invalid = array_key_exists('invalid', $options);
$waitInvite = max(0, (int)($options['wait-invite'] ?? 0));
$host = trim((string)getenv('SIP_HOST'));
$port = (int)(getenv('SIP_PORT') ?: 5060);
$username = trim((string)getenv('SIP_USERNAME'));
$password = (string)getenv('SIP_PASSWORD');
if ($host === '' || $username === '' || $password === '') {
    fwrite(STDERR, "SIP_HOST, SIP_USERNAME e SIP_PASSWORD precisam existir no .env.\n");
    exit(2);
}
if ($invalid) $password .= '-invalid-test';

final class RealPort4000Transport
{
    public Socket $socket;
    public array $sent = [];

    public function __construct()
    {
        $this->socket = new Socket(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$this->socket->bind('0.0.0.0', SipRegisterManager::SIP_PORT)) {
            throw new RuntimeException('Não foi possível bindar UDP :4000: ' . $this->socket->errMsg);
        }
    }

    public function sendto(string $ip, int $port, string $packet): int|false
    {
        $parsed = sip::parse($packet);
        $this->sent[] = [
            'method' => $parsed['method'] ?? '',
            'cseq' => $parsed['headers']['CSeq'][0] ?? '',
            'authorized' => isset($parsed['headers']['Authorization']) || isset($parsed['headers']['Proxy-Authorization']),
            'via' => $parsed['headers']['Via'][0] ?? '',
            'contact' => $parsed['headers']['Contact'][0] ?? '',
        ];
        return $this->socket->sendto($ip, $port, $packet);
    }
}

$exitCode = 1;
Coroutine\run(function () use ($host, $port, $username, $password, $invalid, $waitInvite, &$exitCode): void {
    $transport = new RealPort4000Transport();
    $running = true;
    $inboundInvite = null;
    go(function () use ($transport, &$running, &$inboundInvite): void {
        while ($running) {
            $peer = [];
            $raw = $transport->socket->recvfrom($peer, 0.25);
            if (!is_string($raw) || $raw === '') continue;
            $message = sip::parse($raw);
            if (SipRegisterManager::handleResponse($message, $peer)) continue;
            if (($message['method'] ?? '') === 'INVITE') {
                $inboundInvite = [
                    'source' => ($peer['address'] ?? '') . ':' . ($peer['port'] ?? ''),
                    'call_id' => $message['headers']['Call-ID'][0] ?? '',
                    'cseq' => $message['headers']['CSeq'][0] ?? '',
                ];
            }
        }
    });

    $result = SipRegisterManager::register($transport, [
        'sipServer' => $host . ($port === 5060 ? '' : ':' . $port),
        'sipDomain' => $host,
        'sipUser' => $username,
        'sipPass' => $password,
    ], 1800, 15.0);

    $safeResult = [
        'test' => $invalid ? 'invalid-credential' : 'valid-credential',
        'success' => $result['success'],
        'reason' => $result['reason'],
        'code' => $result['code'],
        'source_port' => $result['source_port'] ?? SipRegisterManager::SIP_PORT,
        'contact_host' => $result['contact_host'] ?? null,
        'contact_port' => $result['contact_port'] ?? null,
        'observed_address' => $result['observed_address'] ?? null,
        'observed_port' => $result['observed_port'] ?? null,
        'nat_port_preserved' => $result['nat_port_preserved'] ?? null,
        'binding_confirmed' => $result['binding_confirmed'] ?? false,
        'binding_contact' => $result['binding_contact'] ?? null,
        'response_contacts' => $result['response']['headers']['Contact'] ?? [],
        'packets' => $transport->sent,
    ];
    echo json_encode($safeResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    if ($result['success'] && $waitInvite > 0) {
        $deadline = microtime(true) + $waitInvite;
        while ($inboundInvite === null && microtime(true) < $deadline) Coroutine::sleep(0.1);
        echo json_encode(['inbound_invite' => $inboundInvite], JSON_PRETTY_PRINT) . PHP_EOL;
    }

    $running = false;
    $transport->socket->close();
    if ($invalid) {
        $exitCode = (!$result['success'] && in_array($result['code'], [401, 403, 407], true)) ? 0 : 1;
    } else {
        $exitCode = ($result['success'] && ($result['contact_port'] ?? null) === 4000) ? 0 : 1;
    }
});
exit($exitCode);
