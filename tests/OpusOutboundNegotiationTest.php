<?php

declare(strict_types=1);

require __DIR__ . '/../libspech/plugins/autoloader.php';
foreach ([
    'OpusConfig.php', 'SipRegisterManager.php', 'SdpHelper.php', 'SipTransactionManager.php', 'SipDialog.php',
    'SipDigestAuth.php', 'PhoneController.php', 'OutboundMediaSession.php', 'OutboundCall.php',
] as $helper) require_once __DIR__ . '/../plugins/Utils/helpers/' . $helper;

use helpers\utils\OpusConfig;
use helpers\utils\OutboundCall;
use helpers\utils\PhoneController;
use libspech\Sip\sip;
use Swoole\Coroutine;

function opusOutboundAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

final class OpusAnswerProvider
{
    /** @var list<array<string,mixed>> */
    public array $sent = [];

    /** @param array<string,mixed> $answerConfig */
    public function __construct(
        private array $answerConfig,
        private int $dtmfPt = 101,
        private int $dtmfRate = 48000,
    ) {}

    public function sendto(string $ip, int $port, string $packet): int|false
    {
        $message = sip::parse($packet);
        $this->sent[] = $message;
        $method = strtoupper((string)$message['method']);
        if ($method === 'INVITE') {
            go(function () use ($message): void {
                Coroutine::sleep(0.002);
                $this->deliver($this->response($message, true));
            });
        } elseif ($method === 'BYE') {
            go(function () use ($message): void {
                Coroutine::sleep(0.002);
                $this->deliver($this->response($message, false));
            });
        }
        return strlen($packet);
    }

    private function deliver(string $packet): void
    {
        PhoneController::instance()->handlePacket(sip::parse($packet), ['address' => '127.0.0.1', 'port' => 5060]);
    }

    private function response(array $request, bool $withSdp): string
    {
        $headers = [
            'Via' => [$request['headers']['Via'][0]], 'From' => [$request['headers']['From'][0]],
            'To' => [$request['headers']['To'][0] . ';tag=opus-answer'],
            'Call-ID' => [$request['headers']['Call-ID'][0]], 'CSeq' => [$request['headers']['CSeq'][0]],
            'Contact' => ['<sip:opus@127.0.0.1:5060>'],
        ];
        $body = '';
        if ($withSdp) {
            $body = "v=0\r\no=- 1 1 IN IP4 127.0.0.1\r\ns=opus-test\r\nc=IN IP4 127.0.0.1\r\nt=0 0\r\n"
                . "m=audio 32000 RTP/AVP 111 {$this->dtmfPt}\r\n"
                . "a=rtpmap:111 opus/48000/2\r\n"
                . 'a=fmtp:111 ' . OpusConfig::buildFmtp($this->answerConfig) . "\r\n"
                . "a=rtpmap:{$this->dtmfPt} telephone-event/{$this->dtmfRate}\r\n"
                . "a=fmtp:{$this->dtmfPt} 0-15\r\n"
                . 'a=ptime:' . (int)$this->answerConfig['ptime'] . "\r\n";
        }
        $raw = "SIP/2.0 200 OK\r\n";
        foreach ($headers as $name => $values) foreach ($values as $value) $raw .= "{$name}: {$value}\r\n";
        if ($body !== '') $raw .= "Content-Type: application/sdp\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}";
        else $raw .= "Content-Length: 0\r\n\r\n";
        return $raw;
    }
}

function opusOutboundAccount(array $config): array
{
    return [
        'sipServer' => '127.0.0.1:5060', 'sipDomain' => 'example.test',
        'sipUser' => 'alice', 'sipPass' => 'fixture-only', 'codec' => 'OPUS/48000/2',
        'trunkCodec' => 'OPUS/48000/2', 'opus' => $config,
    ];
}

Coroutine\run(function (): void {
    $cases = [
        ['mono_accept', OpusConfig::defaults(), OpusConfig::defaults(), 1, 32000, 20],
        ['stereo_accept', OpusConfig::presets()['stereo'], OpusConfig::presets()['stereo'], 2, 96000, 20],
        ['stereo_remote_mono', OpusConfig::presets()['stereo'], OpusConfig::defaults(), 1, 32000, 20],
        ['ptime_remote_10', OpusConfig::defaults(), [...OpusConfig::defaults(), 'ptime' => 10], 1, 32000, 10],
        ['ptime_remote_40', OpusConfig::defaults(), [...OpusConfig::defaults(), 'ptime' => 40], 1, 32000, 40],
    ];

    foreach ($cases as [$label, $local, $answer, $channels, $bitrate, $ptime]) {
        $provider = new OpusAnswerProvider($answer);
        $controller = PhoneController::resetForTests($provider);
        $call = $controller->createOutboundCall(opusOutboundAccount($local), '1000', [
            'opus' => $local,
            'sourceSampleRate' => $local['maxCaptureRate'],
            'sourceChannels' => $local['channels'],
            'noResponseTimeout' => 0.2,
            'provisionalTimeout' => 0.2,
        ]);
        $snapshot = null;
        $memberSnapshot = null;
        $call->onAnswer(function (OutboundCall $answered) use (&$snapshot, &$memberSnapshot): void {
            $snapshot = $answered->effectiveOpusConfig();
            $memberSnapshot = $answered->mediaChannel?->memberByLeg('a');
            $answered->hangup();
        });
        opusOutboundAssert($call->start(), "{$label}: call failed");
        $invite = array_values(array_filter($provider->sent, static fn(array $packet): bool => $packet['method'] === 'INVITE'))[0];
        opusOutboundAssert(in_array('rtpmap:111 OPUS/48000/2', $invite['sdp']['a'] ?? [], true), "{$label}: offer rtpmap");
        opusOutboundAssert((int)$snapshot['channels'] === $channels, "{$label}: negotiated channels");
        opusOutboundAssert((int)$snapshot['maxAverageBitrate'] === $bitrate, "{$label}: negotiated bitrate");
        opusOutboundAssert((int)$snapshot['ptime'] === $ptime, "{$label}: negotiated ptime");
        opusOutboundAssert((int)$memberSnapshot['channels'] === $channels, "{$label}: MediaChannel channels");
        opusOutboundAssert((int)$memberSnapshot['ptime'] === $ptime, "{$label}: MediaChannel ptime");
        opusOutboundAssert((int)$memberSnapshot['opusEncoderApplied']['bitrate'] === $bitrate, "{$label}: native encoder bitrate");
        opusOutboundAssert($controller->activeCallCount() === 0, "{$label}: call cleanup");
    }
});

echo "OK: UAC Opus mono/stereo/remote-mono/ptime answer -> MediaChannel -> native encoder e cleanup.\n";
