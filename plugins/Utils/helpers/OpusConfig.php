<?php

namespace helpers\utils;

/**
 * Canonical RTP/SIP Opus configuration.
 *
 * Opus always uses a 48 kHz RTP clock and two channels in rtpmap (RFC 7587).
 * The actual mono/stereo mode is represented by stereo/sprop-stereo in fmtp and
 * by `channels` in the PCM/encoder pipeline.
 */
final class OpusConfig
{
    public const RTP_RATE = 48000;
    public const RTP_CHANNELS = 2;
    public const ALLOWED_RATES = [8000, 12000, 16000, 24000, 48000];
    public const ALLOWED_BITRATES = [16000, 24000, 32000, 48000, 64000, 96000];
    public const ALLOWED_PACKET_TIMES = [10, 20, 40, 60];

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'profile' => 'standard',
            'channels' => 1,
            'stereo' => false,
            'maxPlaybackRate' => 24000,
            'maxCaptureRate' => 24000,
            'maxAverageBitrate' => 32000,
            'fec' => true,
            'ptime' => 20,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public static function presets(): array
    {
        return [
            'economy' => [
                'profile' => 'economy', 'channels' => 1, 'stereo' => false,
                'maxPlaybackRate' => 24000, 'maxCaptureRate' => 24000,
                'maxAverageBitrate' => 24000, 'fec' => true, 'ptime' => 40,
            ],
            'standard' => self::defaults(),
            'high' => [
                'profile' => 'high', 'channels' => 1, 'stereo' => false,
                'maxPlaybackRate' => 48000, 'maxCaptureRate' => 48000,
                'maxAverageBitrate' => 64000, 'fec' => true, 'ptime' => 20,
            ],
            'stereo' => [
                'profile' => 'stereo', 'channels' => 2, 'stereo' => true,
                'maxPlaybackRate' => 48000, 'maxCaptureRate' => 48000,
                'maxAverageBitrate' => 96000, 'fec' => true, 'ptime' => 20,
            ],
        ];
    }

    /**
     * Normalizes persisted data, frontend aliases and legacy accounts.
     * Invalid values fall back to safe telephony defaults rather than being
     * passed to SDP/native codecs.
     *
     * @return array<string,mixed>
     */
    public static function normalize(?array $input): array
    {
        $input ??= [];
        $defaults = self::defaults();
        $requestedStereo = self::boolValue($input['stereo'] ?? ((int)($input['channels'] ?? 1) === 2));
        $channels = $requestedStereo ? 2 : 1;

        $playback = self::allowedInt(
            $input['maxPlaybackRate'] ?? $input['maxplaybackrate'] ?? null,
            self::ALLOWED_RATES,
            $defaults['maxPlaybackRate']
        );
        $capture = self::allowedInt(
            $input['maxCaptureRate'] ?? $input['sprop-maxcapturerate'] ?? null,
            self::ALLOWED_RATES,
            $defaults['maxCaptureRate']
        );
        $bitrate = self::allowedInt(
            $input['maxAverageBitrate'] ?? $input['bitrate'] ?? $input['maxaveragebitrate'] ?? null,
            self::ALLOWED_BITRATES,
            $defaults['maxAverageBitrate']
        );
        $ptime = self::allowedInt($input['ptime'] ?? null, self::ALLOWED_PACKET_TIMES, $defaults['ptime']);
        $profile = (string)($input['profile'] ?? $defaults['profile']);
        if (!in_array($profile, ['economy', 'standard', 'high', 'stereo', 'custom'], true)) {
            $profile = 'custom';
        }

        return [
            'profile' => $profile,
            'channels' => $channels,
            'stereo' => $channels === 2,
            'maxPlaybackRate' => $playback,
            'maxCaptureRate' => $capture,
            'maxAverageBitrate' => $bitrate,
            'fec' => array_key_exists('fec', $input)
                ? self::boolValue($input['fec'])
                : (array_key_exists('useinbandfec', $input) ? self::boolValue($input['useinbandfec']) : true),
            'ptime' => $ptime,
        ];
    }

    /** @return array<string,int|bool> */
    public static function parseFmtp(?string $fmtp): array
    {
        $parsed = [];
        foreach (explode(';', (string)$fmtp) as $parameter) {
            [$rawKey, $rawValue] = array_pad(explode('=', trim($parameter), 2), 2, null);
            $key = strtolower(trim((string)$rawKey));
            if ($key === '' || $rawValue === null) continue;
            $value = trim($rawValue);
            if (in_array($key, ['useinbandfec', 'stereo', 'sprop-stereo'], true)) {
                $parsed[$key] = self::boolValue($value);
            } elseif (in_array($key, ['maxplaybackrate', 'sprop-maxcapturerate', 'maxaveragebitrate'], true)
                && ctype_digit($value)) {
                $parsed[$key] = (int)$value;
            }
        }
        return $parsed;
    }

    /** @param array<string,mixed> $config */
    public static function buildFmtp(array $config): string
    {
        $config = self::normalize($config);
        $stereo = $config['stereo'] ? 1 : 0;
        return 'maxplaybackrate=' . $config['maxPlaybackRate']
            . ';sprop-maxcapturerate=' . $config['maxCaptureRate']
            . ';maxaveragebitrate=' . $config['maxAverageBitrate']
            . ';useinbandfec=' . ($config['fec'] ? 1 : 0)
            . ';stereo=' . $stereo
            . ';sprop-stereo=' . $stereo;
    }

    /**
     * Symmetric policy for this softphone: both directions use the common
     * mono/stereo, bandwidth, bitrate, FEC and packet-time subset.
     *
     * @param array<string,mixed> $local
     * @param array<string,mixed> $remoteFmtp parsed lower-case fmtp keys
     * @return array<string,mixed>
     */
    public static function negotiate(array $local, array $remoteFmtp, ?int $remotePtime = null): array
    {
        $effective = self::normalize($local);
        $remoteStereo = self::boolValue($remoteFmtp['stereo'] ?? false)
            && self::boolValue($remoteFmtp['sprop-stereo'] ?? false);
        $effective['stereo'] = $effective['stereo'] && $remoteStereo;
        $effective['channels'] = $effective['stereo'] ? 2 : 1;

        $remotePlayback = self::validRateOrDefault($remoteFmtp['maxplaybackrate'] ?? null, self::RTP_RATE);
        $remoteCapture = self::validRateOrDefault($remoteFmtp['sprop-maxcapturerate'] ?? null, self::RTP_RATE);
        $effective['maxPlaybackRate'] = min($effective['maxPlaybackRate'], $remoteCapture);
        $effective['maxCaptureRate'] = min($effective['maxCaptureRate'], $remotePlayback);

        $remoteBitrate = (int)($remoteFmtp['maxaveragebitrate'] ?? 0);
        if ($remoteBitrate > 0) {
            $effective['maxAverageBitrate'] = self::nearestAllowedAtMost(
                min($effective['maxAverageBitrate'], $remoteBitrate),
                self::ALLOWED_BITRATES
            );
        }
        $effective['fec'] = $effective['fec'] && self::boolValue($remoteFmtp['useinbandfec'] ?? false);
        if ($remotePtime !== null && in_array($remotePtime, self::ALLOWED_PACKET_TIMES, true)) {
            $effective['ptime'] = $remotePtime;
        }
        $effective['profile'] = 'custom';
        return $effective;
    }

    /** @param array<string,mixed> $config @return array<string,int|bool> */
    public static function mediaConfig(array $config): array
    {
        $config = self::normalize($config);
        return [
            'maxplaybackrate' => $config['maxPlaybackRate'],
            'sprop-maxcapturerate' => $config['maxCaptureRate'],
            'maxaveragebitrate' => $config['maxAverageBitrate'],
            'useinbandfec' => $config['fec'],
            'stereo' => $config['stereo'],
            'sprop-stereo' => $config['stereo'],
            'ptime' => $config['ptime'],
        ];
    }

    /**
     * Applies only controls actually exposed by the installed opusChannel.
     * FEC and encoder bandwidth are deliberately reported as unsupported.
     *
     * @param object $encoder opusChannel at runtime
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function applyEncoder(object $encoder, array $config): array
    {
        $config = self::normalize($config);
        $encoder->setBitrate($config['maxAverageBitrate']);
        $info = method_exists($encoder, 'getInfo') ? $encoder->getInfo() : [];
        return [
            'bitrate' => (int)($info['encoder']['bitrate'] ?? $config['maxAverageBitrate']),
            'channels' => (int)($info['channels'] ?? $config['channels']),
            'fecNegotiated' => $config['fec'],
            'fecApplied' => false,
            'bandwidthControlApplied' => false,
        ];
    }

    /**
     * Preserves PCM channel planes while delegating rate conversion to the
     * existing libspech/global resampler, whose input is one PCM plane.
     */
    public static function resamplePcm(string $pcm, int $sourceRate, int $targetRate, int $channels): string
    {
        if ($pcm === '' || $sourceRate === $targetRate) return $pcm;
        $channels = max(1, min(2, $channels));
        if ($channels === 1) return \resampler($pcm, $sourceRate, $targetRate);

        $left = '';
        $right = '';
        $usable = strlen($pcm) - (strlen($pcm) % 4);
        for ($offset = 0; $offset < $usable; $offset += 4) {
            $left .= substr($pcm, $offset, 2);
            $right .= substr($pcm, $offset + 2, 2);
        }
        $left = \resampler($left, $sourceRate, $targetRate);
        $right = \resampler($right, $sourceRate, $targetRate);
        $samples = min(intdiv(strlen($left), 2), intdiv(strlen($right), 2));
        $result = '';
        for ($sample = 0; $sample < $samples; $sample++) {
            $result .= substr($left, $sample * 2, 2) . substr($right, $sample * 2, 2);
        }
        return $result;
    }

    private static function boolValue(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value) || is_float($value)) return (int)$value !== 0;
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** @param list<int> $allowed */
    private static function allowedInt(mixed $value, array $allowed, int $default): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        return $value !== false && in_array($value, $allowed, true) ? $value : $default;
    }

    private static function validRateOrDefault(mixed $value, int $default): int
    {
        $value = (int)$value;
        return in_array($value, self::ALLOWED_RATES, true) ? $value : $default;
    }

    /** @param list<int> $allowed */
    private static function nearestAllowedAtMost(int $value, array $allowed): int
    {
        $candidate = $allowed[0];
        foreach ($allowed as $allowedValue) {
            if ($allowedValue > $value) break;
            $candidate = $allowedValue;
        }
        return $candidate;
    }
}
