<?php
declare(strict_types=1);

namespace helpers\utils;

/** Normalizes browser microphone preferences and keeps Opus SDP in sync. */
final class AudioConfig
{
    public const MAX_DEVICE_ID_LENGTH = 512;

    /**
     * @return array{audio:array{microphoneId:string,channels:int,stereo:bool,ptime:int,autoGainControl:bool},opus:array}
     */
    public static function normalize(?array $input, ?array $opusInput): array
    {
        $input ??= [];
        $opus = OpusConfig::normalize($opusInput);

        $stereo = array_key_exists('stereo', $input)
            ? self::boolValue($input['stereo'])
            : (array_key_exists('channels', $input)
                ? (int)$input['channels'] === 2
                : (bool)$opus['stereo']);

        $ptime = array_key_exists('ptime', $input) ? (int)$input['ptime'] : (int)$opus['ptime'];
        $profile = (string)$opus['profile'];
        if (($stereo !== (bool)$opus['stereo'] && (array_key_exists('stereo', $input) || array_key_exists('channels', $input)))
            || ($ptime !== (int)$opus['ptime'] && array_key_exists('ptime', $input))) {
            $profile = 'custom';
        }
        $opus = OpusConfig::normalize([
            ...$opus,
            'profile' => $profile,
            'channels' => $stereo ? 2 : 1,
            'stereo' => $stereo,
            'ptime' => $ptime,
        ]);

        $microphoneId = trim((string)($input['microphoneId'] ?? ''));
        if (strlen($microphoneId) > self::MAX_DEVICE_ID_LENGTH) {
            $microphoneId = substr($microphoneId, 0, self::MAX_DEVICE_ID_LENGTH);
        }

        return [
            'audio' => [
                'microphoneId' => $microphoneId,
                'channels' => (int)$opus['channels'],
                'stereo' => (bool)$opus['stereo'],
                'ptime' => (int)$opus['ptime'],
                'autoGainControl' => array_key_exists('autoGainControl', $input)
                    ? self::boolValue($input['autoGainControl'])
                    : true,
            ],
            'opus' => $opus,
        ];
    }

    private static function boolValue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on';
    }
}
