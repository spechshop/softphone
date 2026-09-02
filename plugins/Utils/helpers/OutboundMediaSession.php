<?php

namespace helpers\utils;

use libspech\Cli\cli;
use libspech\Network\network;
use libspech\Rtp\MediaChannel;
use libspech\Rtp\rtpc;
use libspech\Rtp\rtpChannel;

/** One RTP/media lifecycle per outbound dialog. No SIP socket is created here. */
final class OutboundMediaSession
{
    public readonly int $localPort;
    public ?MediaChannel $mediaChannel = null;
    private \SocketMutable $rtpSocket;
    private bool $active = false;
    private string $callId;
    private string $userCodec;

    public function __construct(string $callId, string $userCodec = 'PCMA/8000')
    {
        $this->callId = $callId;
        $this->userCodec = $userCodec;
        $this->rtpSocket = new \SocketMutable(AF_INET, SOCK_DGRAM, SOL_UDP);
        do {
            $port = network::getFreePort('udp');
            if (($port % 2) !== 0) $port--;
        } while (!$this->rtpSocket->bind('0.0.0.0', $port));
        $this->localPort = (int)$this->rtpSocket->getsockname()['port'];
    }

    public function start(array $remoteSdp): void
    {
        if ($this->active) return;
        $parsed = SdpHelper::parseRemoteSdp($remoteSdp);
        $codec = SdpHelper::chooseCodec($parsed['codecs']);
        if ($codec === null || $parsed['ip'] === '' || $parsed['port'] < 1) {
            throw new \RuntimeException('invalid_remote_sdp');
        }

        $media = new MediaChannel($this->rtpSocket, $this->callId);
        $this->mediaChannel = $media;
        $this->active = true;
        $pt = (int)$codec['pt'];
        $frequency = (int)$codec['rate'];
        $channels = (int)($codec['channels'] ?? 1);
        $mapper = [$pt => strtoupper($codec['name']) . "/{$frequency}/{$channels}"];
        if (is_array($parsed['telephone_event'])) {
            $mapper[(int)$parsed['telephone_event']['pt']] = 'telephone-event/' . (int)$parsed['telephone_event']['rate'] . '/1';
        }
        $media->portList = $this->localPort;
        $media->codecMapper = $mapper;
        $media->txCodecMapper = $mapper;
        $media->rxCodecMapper = $mapper;
        $media->registerPtCodecs($mapper);
        $media->addMember([
            'address' => $parsed['ip'], 'port' => $parsed['port'],
            'codec' => $codec['name'], 'pt' => $pt, 'txPt' => $pt, 'rxPt' => $pt,
            'timestamp' => time(), 'config' => [], 'ssrc' => random_int(0, 0xffffffff),
            'frequency' => $frequency, 'channels' => $channels, 'leg' => 'a',
            'txCodecMapper' => $mapper, 'rxCodecMapper' => $mapper,
        ]);

        $eventPort = network::getFreePort('udp');
        if (!$media->eventSock->bind('0.0.0.0', $eventPort)) {
            throw new \RuntimeException('media_event_socket_bind_failed');
        }
        $portHandler = (int)$media->eventSock->getsockname()['port'];
        $userFrequency = (int)(explode('/', $this->userCodec)[1] ?? 8000);
        $callId = $this->callId;
        // The MediaChannel also decodes packets for its own relay pipeline.
        // Keep independent stateful decoders for the browser bridge so G.729
        // and Opus are never advanced twice through the same codec instance.
        $browserG729Decoder = new \bcg729Channel();
        $browserOpusDecoder = new \opusChannel(48000, $channels);
        $media->onReceive(static function (rtpc $packet, array $peer, MediaChannel $channel, rtpChannel $rtp)
            use ($callId, $portHandler, $userFrequency, $browserG729Decoder, $browserOpusDecoder): void {
            $memberId = "{$peer['address']}:{$peer['port']}";
            $member = $channel->members[$memberId] ?? [];
            $name = strtoupper((string)($member['codec'] ?? $channel->resolveCodecNameFromPt($packet->getCodec()) ?? ''));
            try {
                $pcm = match ($name) {
                    'PCMU' => decodePcmuToPcm($packet->payloadRaw),
                    'PCMA' => decodePcmaToPcm($packet->payloadRaw),
                    'G729' => $browserG729Decoder->decode($packet->payloadRaw),
                    'GSM' => $member['gsmDecodedPcm'] ?? false,
                    'OPUS' => $browserOpusDecoder->decode($packet->payloadRaw),
                    'L16' => decodeL16ToPcm($packet->payloadRaw),
                    default => false,
                };
            } catch (\Throwable) {
                return;
            }
            if (!is_string($pcm) || $pcm === '') return;
            $frequency = (int)($member['frequency'] ?? 8000);
            $id = "{$peer['address']}:{$peer['port']}";
            $channel->eventSock->sendto('127.0.0.1', 9966,
                "{$pcm}__::__{$callId}__::__{$id}__::__{$portHandler}__::__{$userFrequency}__::__{$frequency}");
        });
        $media->onStart(function () use ($media, $userFrequency): void {
            $media->eventSock->sendto('127.0.0.1', 9966, str_repeat('0', 12));
            $buffer = '';
            $frameBytes = max(1, (int)($userFrequency * 0.02)) * 2;
            while ($this->active && $media->active) {
                $peer = null;
                $raw = $media->eventSock->recvfrom($peer, 0.2);
                if (!$raw) continue;
                $buffer .= explode('__::__', $raw, 2)[0];
                while (strlen($buffer) >= $frameBytes) {
                    $pcm = substr($buffer, 0, $frameBytes);
                    $buffer = substr($buffer, $frameBytes);
                    $media->sendPcmToLeg('a', $pcm, $userFrequency, 1);
                }
            }
        });
        $media->packetOnTimeout(function (): void { $this->close(); });
        $media->start();
        cli::pcl("[MEDIA] START Call-ID={$this->callId} RTP=:{$this->localPort} remote={$parsed['ip']}:{$parsed['port']}", 'green');
    }

    public function sendDtmf(string $digit): void
    {
        if ($this->mediaChannel !== null && $this->active) $this->mediaChannel->send2833($digit);
    }

    public function close(): void
    {
        if (!$this->active && $this->mediaChannel === null) {
            try { if (!$this->rtpSocket->isClosed()) $this->rtpSocket->close(); } catch (\Throwable) {}
            return;
        }
        $this->active = false;
        if ($this->mediaChannel !== null) $this->mediaChannel->close();
        $this->mediaChannel = null;
        cli::pcl("[MEDIA] STOP Call-ID={$this->callId}", 'yellow');
    }

    public function isActive(): bool { return $this->active; }
}
