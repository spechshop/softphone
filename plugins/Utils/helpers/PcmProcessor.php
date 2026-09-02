<?php

namespace helpers\utils;

/** Generic PCM16LE channel/rate conversion, independent from any RTP codec. */
final class PcmProcessor
{
    /**
     * Converts directly from the source format to the destination format.
     * At most one logical sample-rate conversion is performed.
     */
    public static function convert(
        string $pcm,
        int $sourceRate,
        int $sourceChannels,
        int $targetRate,
        int $targetChannels,
        ?AudioPipelineMetrics $metrics = null,
        ?callable $resample = null,
    ): string {
        if ($pcm === '') {
            return '';
        }

        self::assertFormat($pcm, $sourceRate, $sourceChannels);
        if ($targetRate <= 0 || !in_array($targetChannels, [1, 2], true)) {
            throw new \InvalidArgumentException('invalid_target_pcm_format');
        }

        $converted = $pcm;
        if ($sourceChannels === 2 && $targetChannels === 1) {
            $converted = self::stereoToMono($converted);
        } elseif ($sourceChannels === 1 && $targetChannels === 2) {
            $converted = self::monoToStereo($converted);
        }

        return self::resample($converted, $sourceRate, $targetRate, $targetChannels, $metrics, $resample);
    }

    /** No-op for equal rates; otherwise one logical conversion is recorded. */
    public static function resample(
        string $pcm,
        int $sourceRate,
        int $targetRate,
        int $channels = 1,
        ?AudioPipelineMetrics $metrics = null,
        ?callable $resample = null,
    ): string {
        if ($pcm === '' || $sourceRate === $targetRate) {
            return $pcm;
        }
        self::assertFormat($pcm, $sourceRate, $channels);
        if ($targetRate <= 0) {
            throw new \InvalidArgumentException('invalid_target_pcm_rate');
        }

        $resample ??= static fn(string $plane, int $from, int $to): string => \resampler($plane, $from, $to);
        $startedNs = hrtime(true);

        if ($channels === 1) {
            $result = $resample($pcm, $sourceRate, $targetRate);
        } else {
            [$left, $right] = self::splitStereo($pcm);
            $left = $resample($left, $sourceRate, $targetRate);
            $right = $resample($right, $sourceRate, $targetRate);
            $result = self::interleaveStereo($left, $right);
        }

        $metrics?->recordResample(
            $sourceRate,
            $targetRate,
            strlen($pcm),
            (hrtime(true) - $startedNs) / 1000,
        );
        return $result;
    }

    /** Mixes equally formatted PCM16LE buffers with saturation. */
    public static function mix(array $buffers): string
    {
        $buffers = array_values(array_filter($buffers, static fn($value): bool => is_string($value) && $value !== ''));
        if ($buffers === []) return '';
        if (count($buffers) === 1) return $buffers[0];

        if (function_exists('mixAudioChannels')) {
            $mixed = \mixAudioChannels($buffers);
            if (is_string($mixed)) return $mixed;
        }

        $length = min(array_map('strlen', $buffers));
        $length -= $length % 2;
        $output = '';
        for ($offset = 0; $offset < $length; $offset += 2) {
            $sum = 0;
            foreach ($buffers as $buffer) {
                $sample = unpack('v', substr($buffer, $offset, 2))[1];
                $sum += $sample >= 0x8000 ? $sample - 0x10000 : $sample;
            }
            $sum = max(-32768, min(32767, $sum));
            $output .= pack('v', $sum & 0xffff);
        }
        return $output;
    }

    public static function durationMs(string $pcm, int $sampleRate, int $channels): float
    {
        if ($sampleRate <= 0 || $channels <= 0) return 0.0;
        return (strlen($pcm) * 1000) / ($sampleRate * $channels * 2);
    }

    public static function bytesForDuration(int $sampleRate, int $channels, int $durationMs): int
    {
        return max(2 * $channels, (int)round($sampleRate * ($durationMs / 1000)) * $channels * 2);
    }

    private static function assertFormat(string $pcm, int $sampleRate, int $channels): void
    {
        if ($sampleRate <= 0 || !in_array($channels, [1, 2], true)
            || (strlen($pcm) % (2 * $channels)) !== 0) {
            throw new \InvalidArgumentException('invalid_source_pcm_format');
        }
    }

    private static function monoToStereo(string $pcm): string
    {
        $result = '';
        for ($offset = 0, $length = strlen($pcm); $offset < $length; $offset += 2) {
            $sample = substr($pcm, $offset, 2);
            $result .= $sample . $sample;
        }
        return $result;
    }

    private static function stereoToMono(string $pcm): string
    {
        $result = '';
        for ($offset = 0, $length = strlen($pcm); $offset < $length; $offset += 4) {
            $values = unpack('vleft/vright', substr($pcm, $offset, 4));
            $left = $values['left'] >= 0x8000 ? $values['left'] - 0x10000 : $values['left'];
            $right = $values['right'] >= 0x8000 ? $values['right'] - 0x10000 : $values['right'];
            $average = (int)(($left + $right) / 2);
            $result .= pack('v', $average & 0xffff);
        }
        return $result;
    }

    /** @return array{string,string} */
    private static function splitStereo(string $pcm): array
    {
        $left = '';
        $right = '';
        for ($offset = 0, $length = strlen($pcm); $offset < $length; $offset += 4) {
            $left .= substr($pcm, $offset, 2);
            $right .= substr($pcm, $offset + 2, 2);
        }
        return [$left, $right];
    }

    private static function interleaveStereo(string $left, string $right): string
    {
        $samples = min(intdiv(strlen($left), 2), intdiv(strlen($right), 2));
        $result = '';
        for ($sample = 0; $sample < $samples; $sample++) {
            $offset = $sample * 2;
            $result .= substr($left, $offset, 2) . substr($right, $offset, 2);
        }
        return $result;
    }
}
