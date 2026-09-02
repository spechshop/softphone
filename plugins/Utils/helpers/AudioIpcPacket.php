<?php

namespace helpers\utils;

/** Versioned binary PCM datagram used between audio.php and media sessions. */
final class AudioIpcPacket
{
    public const MAGIC = 'SPCM';
    public const VERSION = 1;
    public const HEADER_BYTES = 32;

    public function __construct(
        public readonly string $payload,
        public readonly string $stream,
        public readonly string $source,
        public readonly int $sampleRate,
        public readonly int $channels = 1,
        public readonly int $replyPort = 0,
        public readonly int $sentAtNs = 0,
        public readonly int $flags = 0,
    ) {
    }

    public function encode(): string
    {
        $streamLength = strlen($this->stream);
        $sourceLength = strlen($this->source);
        if ($this->payload === '' || $streamLength < 1 || $sourceLength < 1
            || $streamLength > 0xffff || $sourceLength > 0xffff
            || $this->sampleRate <= 0 || !in_array($this->channels, [1, 2], true)
            || (strlen($this->payload) % (2 * $this->channels)) !== 0
            || $this->replyPort < 0 || $this->replyPort > 0xffff) {
            throw new \InvalidArgumentException('invalid_audio_ipc_packet');
        }

        $sentAtNs = $this->sentAtNs > 0 ? $this->sentAtNs : hrtime(true);
        return pack(
            'a4CCCCNNNJnn',
            self::MAGIC,
            self::VERSION,
            $this->flags,
            $this->channels,
            0,
            $this->sampleRate,
            strlen($this->payload),
            $this->replyPort,
            $sentAtNs,
            $streamLength,
            $sourceLength,
        ) . $this->stream . $this->source . $this->payload;
    }

    public static function decode(string $datagram): ?self
    {
        if (strlen($datagram) < self::HEADER_BYTES || substr($datagram, 0, 4) !== self::MAGIC) {
            return null;
        }
        $header = unpack(
            'a4magic/Cversion/Cflags/Cchannels/Creserved/NsampleRate/NpayloadLength/NreplyPort/JsentAtNs/nstreamLength/nsourceLength',
            $datagram,
        );
        if (!is_array($header) || $header['version'] !== self::VERSION
            || !in_array((int)$header['channels'], [1, 2], true)
            || (int)$header['sampleRate'] <= 0) {
            return null;
        }

        $metadataLength = (int)$header['streamLength'] + (int)$header['sourceLength'];
        $expectedLength = self::HEADER_BYTES + $metadataLength + (int)$header['payloadLength'];
        if (strlen($datagram) !== $expectedLength || (int)$header['payloadLength'] <= 0) {
            return null;
        }
        if (((int)$header['payloadLength'] % (2 * (int)$header['channels'])) !== 0) return null;
        $offset = self::HEADER_BYTES;
        $stream = substr($datagram, $offset, (int)$header['streamLength']);
        $offset += (int)$header['streamLength'];
        $source = substr($datagram, $offset, (int)$header['sourceLength']);
        $offset += (int)$header['sourceLength'];
        if ($stream === '' || $source === '') return null;

        return new self(
            substr($datagram, $offset),
            $stream,
            $source,
            (int)$header['sampleRate'],
            (int)$header['channels'],
            (int)$header['replyPort'],
            (int)$header['sentAtNs'],
            (int)$header['flags'],
        );
    }

    /** Compatibility decoder for engine -> audio.php separator packets. */
    public static function decodeLegacyPlayback(string $datagram): ?self
    {
        $parts = explode('__::__', $datagram, 7);
        if (count($parts) < 6) return null;
        [$payload, $stream, $source, $replyPort, $_userRate, $sourceRate] = $parts;
        $channels = max(1, min(2, (int)($parts[6] ?? 1)));
        if ($payload === '' || $stream === '' || $source === '' || (int)$sourceRate <= 0) return null;
        return new self($payload, $stream, $source, (int)$sourceRate, $channels, (int)$replyPort);
    }

    /** Compatibility decoder for audio.php -> media separator packets. */
    public static function decodeLegacyCapture(
        string $datagram,
        int $fallbackRate,
        int $fallbackChannels,
    ): ?self {
        $parts = explode('__::__', $datagram, 3);
        $channels = max(1, min(2, $fallbackChannels));
        if (($parts[0] ?? '') === '' || (strlen($parts[0]) % (2 * $channels)) !== 0) return null;
        return new self(
            $parts[0],
            $parts[1] ?? 'legacy',
            $parts[2] ?? 'legacy',
            $fallbackRate,
            $channels,
        );
    }
}
