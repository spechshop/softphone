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
    /** @var array<string,mixed> */
    private array $offeredCodec;
    /** @var array<string,mixed> */
    private array $localOpusConfig;
    /** @var array<string,mixed>|null */
    private ?array $negotiatedOpusConfig = null;
    private int $sourceSampleRate;
    private int $sourceChannels;
    private ?object $browserG729Decoder = null;
    private ?object $browserOpusDecoder = null;

    public function __construct(
        string $callId,
        string $userCodec = 'PCMA/8000',
        array $offeredCodec = ['name' => 'PCMA', 'pt' => 8, 'rate' => 8000, 'channels' => 1],
        ?array $opusConfig = null,
        int $sourceSampleRate = 8000,
        int $sourceChannels = 1,
    )
    {
        $this->callId = $callId;
        $this->userCodec = $userCodec;
        $this->offeredCodec = $offeredCodec;
        $this->localOpusConfig = OpusConfig::normalize($opusConfig);
        $this->sourceSampleRate = max(8000, min(48000, $sourceSampleRate));
        $this->sourceChannels = max(1, min(2, $sourceChannels));
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
        $codec = SdpHelper::chooseCodec($parsed['codecs'], (string)$this->offeredCodec['name']);
        if ($codec === null || $parsed['ip'] === '' || $parsed['port'] < 1) {
            throw new \RuntimeException('invalid_remote_sdp');
        }
        if (strcasecmp((string)$codec['name'], (string)$this->offeredCodec['name']) !== 0
            || (int)$codec['pt'] !== (int)$this->offeredCodec['pt']) {
            throw new \RuntimeException('remote_answer_changed_offered_payload');
        }

        $isOpus = strcasecmp((string)$codec['name'], 'opus') === 0;
        if ($isOpus) {
            if ((int)$codec['rate'] !== OpusConfig::RTP_RATE) {
                throw new \RuntimeException('invalid_opus_rtp_clock');
            }
            $this->negotiatedOpusConfig = OpusConfig::negotiate(
                $this->localOpusConfig,
                (array)($codec['fmtp_parsed'] ?? []),
                is_int($parsed['ptime']) ? $parsed['ptime'] : null,
            );
        }

        $media = new MediaChannel($this->rtpSocket, $this->callId);
        $this->mediaChannel = $media;
        $this->active = true;
        $pt = (int)$codec['pt'];
        $frequency = (int)$codec['rate'];
        $channels = $isOpus
            ? (int)$this->negotiatedOpusConfig['channels']
            : (int)($codec['channels'] ?? 1);
        $mapper = [$pt => strtoupper($codec['name']) . "/{$frequency}/{$channels}"];
        if (is_array($parsed['telephone_event'])) {
            $mapper[(int)$parsed['telephone_event']['pt']] = 'telephone-event/' . (int)$parsed['telephone_event']['rate'] . '/1';
        }
        $media->portList = $this->localPort;
        $packetTime = $isOpus ? (int)$this->negotiatedOpusConfig['ptime'] : 20;
        $media->setPacketTime($packetTime);
        $media->codecMapper = $mapper;
        $media->txCodecMapper = $mapper;
        $media->rxCodecMapper = $mapper;
        $media->registerPtCodecs($mapper);
        if (is_array($parsed['telephone_event']) && (int)$parsed['telephone_event']['pt'] !== 101) {
            // Remove libspech's legacy dynamic-PT fallback so RFC 4733 uses the
            // PT explicitly negotiated in this answer.
            unset($media->ptCodecs[101], $media->ptFrequencies[101]);
        }
        $media->addMember([
            'address' => $parsed['ip'], 'port' => $parsed['port'],
            'codec' => $codec['name'], 'pt' => $pt, 'txPt' => $pt, 'rxPt' => $pt,
            'timestamp' => time(),
            'config' => $isOpus ? OpusConfig::mediaConfig($this->negotiatedOpusConfig) : [],
            'ssrc' => random_int(0, 0xffffffff),
            'frequency' => $frequency, 'channels' => $channels, 'leg' => 'a',
            'ptime' => $packetTime,
            'txCodecMapper' => $mapper, 'rxCodecMapper' => $mapper,
        ]);
        $memberId = $parsed['ip'] . ':' . $parsed['port'];
        if ($isOpus && isset($media->members[$memberId]['opusEncoder'])) {
            $media->members[$memberId]['opusEffectiveConfig'] = $this->negotiatedOpusConfig;
            $media->members[$memberId]['opusEncoderApplied'] = OpusConfig::applyEncoder(
                $media->members[$memberId]['opusEncoder'],
                $this->negotiatedOpusConfig,
            );
        }

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
        $browserG729Decoder = $this->browserG729Decoder = new \bcg729Channel();
        $browserOpusDecoder = $this->browserOpusDecoder = new \opusChannel(48000, $channels);
        $opusPlaybackRate = $isOpus ? (int)$this->negotiatedOpusConfig['maxPlaybackRate'] : 0;
        $media->onReceive(static function (rtpc $packet, array $peer, MediaChannel $channel, rtpChannel $rtp)
            use ($callId, $portHandler, $userFrequency, $browserG729Decoder, $browserOpusDecoder, $channels, $opusPlaybackRate): void {
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
            if ($name === 'OPUS' && $opusPlaybackRate > 0 && $opusPlaybackRate < OpusConfig::RTP_RATE) {
                $pcm = OpusConfig::resamplePcm($pcm, OpusConfig::RTP_RATE, $opusPlaybackRate, $channels);
                $frequency = $opusPlaybackRate;
            }
            $id = "{$peer['address']}:{$peer['port']}";
            $channel->eventSock->sendto('127.0.0.1', 9966,
                "{$pcm}__::__{$callId}__::__{$id}__::__{$portHandler}__::__{$userFrequency}__::__{$frequency}__::__{$channels}");
        });
        $sourceSampleRate = $this->sourceSampleRate;
        $sourceChannels = $this->sourceChannels;
        $media->onStart(function () use ($media, $sourceSampleRate, $sourceChannels, $isOpus): void {
            $media->eventSock->sendto('127.0.0.1', 9966, str_repeat('0', 12));
            while ($this->active && $media->active) {
                $peer = null;
                $raw = $media->eventSock->recvfrom($peer, 0.2);
                if (!$raw) continue;
                $pcm = explode('__::__', $raw, 2)[0];
                if ($pcm !== '') {
                    $mediaRate = $sourceSampleRate;
                    // libspech owns packetization; only make its existing mono
                    // resampler channel-safe before injection when PCM is stereo.
                    if ($isOpus && $sourceChannels === 2 && $sourceSampleRate !== OpusConfig::RTP_RATE) {
                        $pcm = OpusConfig::resamplePcm($pcm, $sourceSampleRate, OpusConfig::RTP_RATE, 2);
                        $mediaRate = OpusConfig::RTP_RATE;
                    }
                    $media->sendPcmToLeg('a', $pcm, $mediaRate, $sourceChannels);
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
        try {
            if ($this->browserOpusDecoder !== null && method_exists($this->browserOpusDecoder, 'destroy')) {
                $this->browserOpusDecoder->destroy();
            }
            if ($this->browserG729Decoder !== null && method_exists($this->browserG729Decoder, 'close')) {
                $this->browserG729Decoder->close();
            }
        } catch (\Throwable) {}
        $this->browserOpusDecoder = null;
        $this->browserG729Decoder = null;
        cli::pcl("[MEDIA] STOP Call-ID={$this->callId}", 'yellow');
    }

    public function isActive(): bool { return $this->active; }

    /** @return array<string,mixed>|null */
    public function effectiveOpusConfig(): ?array { return $this->negotiatedOpusConfig; }
}
