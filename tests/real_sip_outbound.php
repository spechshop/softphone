<?php

/**
 * Authorized real-provider smoke test. Output is intentionally credential-safe.
 * It self-calls the configured account, auto-answers the inbound leg on the
 * same UDP :4000 listener, hangs up the outbound leg, renews REGISTER, then
 * repeats once to prove inbound still arrives after an outbound call.
 */

require __DIR__ . '/../libspech/plugins/autoloader.php';
foreach ([
    'SipRegisterManager.php', 'SdpHelper.php', 'SipTransactionManager.php', 'SipDialog.php',
    'SipDigestAuth.php', 'PhoneController.php', 'OutboundMediaSession.php', 'OutboundCall.php',
] as $helper) require_once __DIR__ . '/../plugins/Utils/helpers/' . $helper;

use helpers\utils\OutboundCall;
use helpers\utils\PhoneController;
use helpers\utils\SdpHelper;
use helpers\utils\SipRegisterManager;
use libspech\Network\network;
use libspech\Rtp\rtpChannel;
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

final class RealSharedSipTransport
{
    public Socket $socket;
    public array $sent = [];
    public function __construct()
    {
        $this->socket = new Socket(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$this->socket->bind('0.0.0.0', 4000)) throw new RuntimeException('udp_4000_bind_failed');
    }
    public function sendto(string $ip, int $port, string $packet): int|false
    {
        $message = sip::parse($packet);
        $method = (string)($message['method'] ?? '');
        $this->sent[] = [
            'method' => $method, 'cseq_method' => explode(' ', $message['headers']['CSeq'][0] ?? '')[1] ?? '',
            'authenticated' => isset($message['headers']['Authorization']) || isset($message['headers']['Proxy-Authorization']),
            'source_port' => (int)($this->socket->getsockname()['port'] ?? 0),
            'via_port_4000' => str_contains((string)($message['headers']['Via'][0] ?? ''), ':4000'),
        ];
        return $this->socket->sendto($ip, $port, $packet);
    }
}

function safeResponse(array $request, int $code, string $reason, array $extra = [], string $body = ''): string
{
    $headers = [
        'Via' => $request['headers']['Via'] ?? [''], 'From' => $request['headers']['From'] ?? [''],
        'To' => $request['headers']['To'] ?? [''], 'Call-ID' => $request['headers']['Call-ID'] ?? [''],
        'CSeq' => $request['headers']['CSeq'] ?? [''],
    ];
    $headers = array_replace($headers, $extra);
    $model = ['method' => (string)$code, 'methodForParser' => "SIP/2.0 {$code} {$reason}", 'headers' => $headers];
    if ($body !== '') { $model['body'] = $body; $model['headers']['Content-Type'] = ['application/sdp']; }
    return sip::renderSolution($model);
}

$exit = 1;
Coroutine\run(function () use ($host, $port, $username, $password, &$exit): void {
    $transport = new RealSharedSipTransport();
    $controller = PhoneController::resetForTests($transport);
    $running = true;
    $inboundInvites = [];
    $inboundAcks = 0;
    $inboundByes = 0;
    $answered = [];
    $rtpSockets = [];
    $inboundRtpPackets = 0;
    $inboundDtmfPackets = 0;
    $outboundRtpPackets = [];

    go(function () use ($transport, $controller, $username, &$running, &$inboundInvites, &$inboundAcks, &$inboundByes,
        &$answered, &$rtpSockets, &$inboundRtpPackets, &$inboundDtmfPackets): void {
        while ($running) {
            $peer = [];
            $raw = $transport->socket->recvfrom($peer, 0.2);
            if (!is_string($raw) || $raw === '') continue;
            $message = sip::parse($raw);
            if (SipRegisterManager::handleResponse($message, $peer)) continue;
            if ($controller->handlePacket($message, $peer)) continue;
            $method = strtoupper((string)($message['method'] ?? ''));
            $callId = (string)($message['headers']['Call-ID'][0] ?? '');
            if ($method === 'INVITE') {
                if (isset($answered[$callId])) {
                    $transport->sendto($peer['address'], $peer['port'], $answered[$callId]);
                    continue;
                }
                $inboundInvites[] = $callId;
                $tag = bin2hex(random_bytes(6));
                $taggedTo = ($message['headers']['To'][0] ?? '') . ';tag=' . $tag;
                $transport->sendto($peer['address'], $peer['port'], safeResponse($message, 100, 'Trying'));
                $transport->sendto($peer['address'], $peer['port'], safeResponse($message, 180, 'Ringing', ['To' => [$taggedTo]]));
                $rtp = new Socket(AF_INET, SOCK_DGRAM, SOL_UDP);
                $rtp->bind('0.0.0.0', 0);
                $rtpSockets[] = $rtp;
                $localIp = network::getLocalIp(4);
                $remoteMedia = SdpHelper::parseRemoteSdp($message['sdp'] ?? []);
                $sdp = SdpHelper::buildLocalSdp($localIp, (int)$rtp->getsockname()['port'],
                    ['name' => 'PCMA', 'pt' => 8, 'rate' => 8000, 'channels' => 1], null);
                $ok = safeResponse($message, 200, 'OK', [
                    'To' => [$taggedTo], 'Contact' => ["<sip:{$username}@{$localIp}:4000>"],
                    'Record-Route' => $message['headers']['Record-Route'] ?? [],
                ], $sdp);
                $answered[$callId] = $ok;
                Coroutine::sleep(0.03);
                $transport->sendto($peer['address'], $peer['port'], $ok);
                go(function () use ($rtp, $remoteMedia, &$inboundRtpPackets, &$inboundDtmfPackets): void {
                    // Inbound leg -> provider -> outbound MediaChannel.
                    Coroutine::sleep(0.15);
                    $channel = new rtpChannel(8, 8000, 20);
                    $payload = encodePcmToPcma(str_repeat("\x01\x00", 160));
                    for ($i = 0; $i < 12; $i++) {
                        $rtp->sendto($remoteMedia['ip'], $remoteMedia['port'], $channel->buildAudioPacket($payload));
                        Coroutine::sleep(0.02);
                    }
                    // Outbound leg -> provider -> this inbound RTP socket.
                    $deadline = microtime(true) + 1.2;
                    while (microtime(true) < $deadline) {
                        $rtpPeer = [];
                        $packet = $rtp->recvfrom($rtpPeer, 0.1);
                        if (!is_string($packet) || strlen($packet) < 12) continue;
                        $pt = ord($packet[1]) & 0x7f;
                        if ($pt === 101) $inboundDtmfPackets++;
                        else $inboundRtpPackets++;
                    }
                });
            } elseif ($method === 'ACK' && isset($answered[$callId])) {
                $inboundAcks++;
            } elseif ($method === 'BYE' && isset($answered[$callId])) {
                $inboundByes++;
                $transport->sendto($peer['address'], $peer['port'], safeResponse($message, 200, 'OK'));
            } elseif ($method === 'OPTIONS') {
                $transport->sendto($peer['address'], $peer['port'], safeResponse($message, 200, 'OK'));
            }
        }
    });

    $account = ['sipServer' => $host . ($port === 5060 ? '' : ':' . $port), 'sipDomain' => $host,
        'sipUser' => $username, 'sipPass' => $password];
    $before = SipRegisterManager::register($transport, $account, 1800, 15.0);
    $callResults = [];
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $call = $controller->createOutboundCall($account, $username, [
            'trunkCodec' => 'PCMA/8000', 'userCodec' => 'PCMA/8000',
            'noResponseTimeout' => 15.0, 'provisionalTimeout' => 30.0,
        ]);
        $established = false;
        $call->onAnswer(function (OutboundCall $call) use (&$established, &$outboundRtpPackets): void {
            $established = true;
            $call->mediaChannel?->setAudioMetricsEnabled(true);
            go(function () use ($call, &$outboundRtpPackets): void {
                Coroutine::sleep(0.25);
                $pcm = str_repeat("\x01\x00", 160);
                for ($i = 0; $i < 12; $i++) {
                    $call->mediaChannel?->sendPcmToLeg('a', $pcm, 8000, 1);
                    Coroutine::sleep(0.02);
                }
                $call->sendDtmf('5');
                Coroutine::sleep(0.25);
                $outboundRtpPackets[] = (int)($call->mediaChannel?->getAudioMetrics()['total_packets'] ?? 0);
                $call->hangup();
            });
        });
        $callResults[] = ['completed' => $call->start(), 'established' => $established];
        Coroutine::sleep(0.2);
    }
    $after = SipRegisterManager::register($transport, $account, 1800, 15.0);
    Coroutine::sleep(0.3);
    $running = false;
    foreach ($rtpSockets as $rtp) $rtp->close();
    $transport->socket->close();

    $signaling = array_values(array_filter($transport->sent, static fn(array $p): bool =>
        in_array($p['method'], ['INVITE','ACK','BYE','CANCEL'], true)));
    $allPhysical4000 = $signaling !== [];
    foreach ($signaling as $packet) $allPhysical4000 = $allPhysical4000 && $packet['source_port'] === 4000 && $packet['via_port_4000'];
    $summary = [
        'register_before' => ['success' => $before['success'], 'code' => $before['code'],
            'source_port' => $before['source_port'] ?? null, 'binding_port' => $before['contact_port'] ?? null,
            'observed_port' => $before['observed_port'] ?? null, 'binding_confirmed' => $before['binding_confirmed'] ?? false],
        'calls' => $callResults,
        'inbound_invites' => count(array_unique($inboundInvites)), 'inbound_acks' => $inboundAcks, 'inbound_byes' => $inboundByes,
        'rtp' => ['outbound_received_packets' => $outboundRtpPackets,
            'inbound_received_packets' => $inboundRtpPackets, 'inbound_dtmf_packets' => $inboundDtmfPackets],
        'signaling_source_4000' => $allPhysical4000,
        'signaling_methods' => array_values(array_unique(array_column($signaling, 'method'))),
        'register_after' => ['success' => $after['success'], 'code' => $after['code'],
            'source_port' => $after['source_port'] ?? null, 'binding_port' => $after['contact_port'] ?? null,
            'observed_port' => $after['observed_port'] ?? null, 'binding_confirmed' => $after['binding_confirmed'] ?? false],
        'active_calls' => $controller->activeCallCount(), 'active_dialogs' => $controller->activeDialogCount(),
        'pending_transactions' => $controller->pendingTransactionCount(),
    ];
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $callsOk = count(array_filter($callResults, static fn(array $v): bool => $v['established'])) === 2;
    $mediaOk = array_sum($outboundRtpPackets) > 0 && $inboundRtpPackets > 0 && $inboundDtmfPackets > 0;
    $exit = ($before['success'] && $after['success'] && $allPhysical4000 && $callsOk && $mediaOk
        && count(array_unique($inboundInvites)) >= 2 && $controller->pendingTransactionCount() === 0) ? 0 : 1;
});
exit($exit);
