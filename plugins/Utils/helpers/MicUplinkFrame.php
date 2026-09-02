<?php

namespace helpers\utils;

/** Binary browser microphone frame (all numeric fields are network byte order). */
final class MicUplinkFrame
{
    public const MAGIC = 'MU';
    public const VERSION = 1;
    public const FORMAT_PCM16_LE = 1;
    public const FLAG_STEREO = 0x0001;
    public const FLAG_FRAME_10MS = 0x0002;
    public const HEADER_BYTES = 20;
    public const FRAME_MS = 20;

    public function __construct(
        public readonly int $sequence,
        public readonly int $captureTimestampMs,
        public readonly int $sampleRate,
        public readonly int $samples,
        public readonly string $payload,
        public readonly int $format = self::FORMAT_PCM16_LE,
        public readonly int $flags = 0,
        public readonly int $arrivalTimestampMs = 0,
    ) {
    }

    public static function decode(string $packet, ?int $expectedSampleRate = null, int $arrivalTimestampMs = 0): ?self
    {
        if (strlen($packet) < self::HEADER_BYTES) {
            return null;
        }

        $header = unpack(
            'a2magic/Cversion/Cformat/Nsequence/NcaptureTimestampMs/nsampleRate/nsamples/npayloadLength/nflags',
            substr($packet, 0, self::HEADER_BYTES)
        );

        if (!is_array($header)
            || $header['magic'] !== self::MAGIC
            || $header['version'] !== self::VERSION
            || $header['format'] !== self::FORMAT_PCM16_LE
        ) {
            return null;
        }

        $sampleRate = (int)$header['sampleRate'];
        $samples = (int)$header['samples'];
        $payloadLength = (int)$header['payloadLength'];
        $channels = (((int)$header['flags'] & self::FLAG_STEREO) !== 0) ? 2 : 1;
        $frameMs = (((int)$header['flags'] & self::FLAG_FRAME_10MS) !== 0) ? 10 : self::FRAME_MS;
        $expectedSamples = (int)round($sampleRate * ($frameMs / 1000)) * $channels;

        if ($sampleRate < 8000 || $sampleRate > 48000
            || ($expectedSampleRate !== null && $sampleRate !== $expectedSampleRate)
            || $samples !== $expectedSamples
            || $payloadLength !== $samples * 2
            || strlen($packet) !== self::HEADER_BYTES + $payloadLength
        ) {
            return null;
        }

        return new self(
            (int)$header['sequence'],
            (int)$header['captureTimestampMs'],
            $sampleRate,
            $samples,
            substr($packet, self::HEADER_BYTES),
            (int)$header['format'],
            (int)$header['flags'],
            $arrivalTimestampMs,
        );
    }

    public function channels(): int
    {
        return ($this->flags & self::FLAG_STEREO) !== 0 ? 2 : 1;
    }

    public function frameMs(): int
    {
        return ($this->flags & self::FLAG_FRAME_10MS) !== 0 ? 10 : self::FRAME_MS;
    }

    public function encode(): string
    {
        return pack(
            'a2CCNNnnnn',
            self::MAGIC,
            self::VERSION,
            $this->format,
            $this->sequence,
            $this->captureTimestampMs,
            $this->sampleRate,
            $this->samples,
            strlen($this->payload),
            $this->flags,
        ) . $this->payload;
    }
}
