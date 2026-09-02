<?php

namespace helpers\utils;

class SdpHelper
{
    private static array $codecPriority = [
        ['name' => 'PCMA', 'pt' => 8, 'rate' => 8000, 'channels' => 1],
        ['name' => 'PCMU', 'pt' => 0, 'rate' => 8000, 'channels' => 1],
        ['name' => 'G729', 'pt' => 18, 'rate' => 8000, 'channels' => 1],
        ['name' => 'GSM', 'pt' => 3, 'rate' => 8000, 'channels' => 1],
        ['name' => 'opus', 'pt' => null, 'rate' => 48000, 'channels' => 2],
        ['name' => 'L16', 'pt' => null, 'rate' => 8000, 'channels' => 1],
    ];

    /**
     * Parse remote SDP (already parsed array from sip::parse()['sdp']).
     * Returns ['ip', 'port', 'codecs', 'telephone_event', 'ptime'].
     * Opus codecs include the typed `fmtp_parsed` structure and retain the
     * RTP rtpmap channel count separately as `rtp_channels`.
     */
    public static function parseRemoteSdp(array $sdp): array
    {
        $result = [
            'ip' => '',
            'port' => 0,
            'codecs' => [],
            'telephone_event' => null,
            'ptime' => null,
        ];

        // c= -> "IN IP4 x.x.x.x"
        if (!empty($sdp['c'][0])) {
            $parts = explode(' ', trim($sdp['c'][0]));
            $result['ip'] = $parts[2] ?? '';
        }

        // m= -> "audio PORT RTP/AVP pt1 pt2 ..."
        $payloadTypes = [];
        foreach ($sdp['m'] ?? [] as $mLine) {
            $parts = explode(' ', trim($mLine));
            if (($parts[0] ?? '') === 'audio') {
                $result['port'] = (int)($parts[1] ?? 0);
                $payloadTypes = array_slice($parts, 3);
                break;
            }
        }

        // a= -> rtpmap / fmtp
        $rtpmap = [];
        $fmtp = [];
        foreach ($sdp['a'] ?? [] as $aLine) {
            $aLine = preg_replace('/^a=/i', '', trim($aLine));
            if (stripos($aLine, 'rtpmap:') === 0) {
                $rest = substr($aLine, 7);
                [$pt, $codecStr] = array_pad(explode(' ', $rest, 2), 2, '');
                $pt = (int)$pt;
                $codecParts = explode('/', $codecStr);
                $rtpmap[$pt] = [
                    'name' => $codecParts[0],
                    'rate' => (int)($codecParts[1] ?? 8000),
                    'channels' => (int)($codecParts[2] ?? 1),
                ];
            } elseif (stripos($aLine, 'fmtp:') === 0) {
                $rest = substr($aLine, 5);
                [$pt, $params] = array_pad(explode(' ', $rest, 2), 2, '');
                $fmtp[(int)$pt] = $params;
            } elseif (preg_match('/^ptime\s*:\s*(\d+)$/i', $aLine, $match) === 1) {
                $result['ptime'] = (int)$match[1];
            }
        }

        $staticCodecs = [
            0 => ['name' => 'PCMU', 'rate' => 8000, 'channels' => 1],
            8 => ['name' => 'PCMA', 'rate' => 8000, 'channels' => 1],
            18 => ['name' => 'G729', 'rate' => 8000, 'channels' => 1],
            3 => ['name' => 'GSM', 'rate' => 8000, 'channels' => 1],
        ];

        foreach ($payloadTypes as $ptStr) {
            $pt = (int)$ptStr;
            $info = $rtpmap[$pt] ?? $staticCodecs[$pt] ?? null;
            if ($info === null) continue;

            $upperName = strtoupper($info['name']);
            if ($upperName === 'TELEPHONE-EVENT') {
                // m-line order is the remote preference order. Keep the first
                // supported telephone-event mapping when more than one exists.
                if ($result['telephone_event'] === null) {
                    $result['telephone_event'] = [
                        'pt' => $pt,
                        'rate' => $info['rate'],
                        'fmtp' => $fmtp[$pt] ?? '0-15',
                    ];
                }
                continue;
            }

            $codec = [
                'pt' => $pt,
                'name' => $info['name'],
                'rate' => $info['rate'],
                'channels' => $info['channels'],
                'rtp_channels' => $info['channels'],
                'fmtp' => $fmtp[$pt] ?? null,
            ];
            if ($upperName === 'OPUS') {
                $codec['fmtp_parsed'] = OpusConfig::parseFmtp($codec['fmtp']);
                // RFC 7587 rtpmap channels is always 2. PCM semantics comes from fmtp.
                $codec['channels'] = !empty($codec['fmtp_parsed']['stereo'])
                    && !empty($codec['fmtp_parsed']['sprop-stereo']) ? 2 : 1;
            }
            $result['codecs'][] = $codec;
        }

        return $result;
    }

    /**
     * Choose the best codec from a list using the priority order.
     */
    public static function chooseCodec(array $remoteCodecs, ?string $preferredName = null): ?array
    {
        $priority = self::$codecPriority;
        $preferredName = strtoupper(trim((string)$preferredName));
        if ($preferredName !== '') {
            usort($priority, static function (array $left, array $right) use ($preferredName): int {
                $leftPreferred = strtoupper($left['name']) === $preferredName;
                $rightPreferred = strtoupper($right['name']) === $preferredName;
                return $leftPreferred === $rightPreferred ? 0 : ($leftPreferred ? -1 : 1);
            });
        }
        foreach ($priority as $preferred) {
            foreach ($remoteCodecs as $remote) {
                if (strcasecmp($remote['name'], $preferred['name']) === 0) {
                    if (strcasecmp($remote['name'], 'opus') === 0
                        && ((int)$remote['rate'] !== OpusConfig::RTP_RATE
                            || (int)($remote['rtp_channels'] ?? 0) !== OpusConfig::RTP_CHANNELS)) {
                        continue;
                    }
                    return [
                        'pt' => $preferred['pt'] ?? $remote['pt'],
                        'name' => $remote['name'],
                        'rate' => $remote['rate'],
                        'channels' => $remote['channels'],
                        'rtp_channels' => $remote['rtp_channels'] ?? $remote['channels'],
                        'fmtp' => $remote['fmtp'] ?? null,
                        'fmtp_parsed' => $remote['fmtp_parsed'] ?? [],
                    ];
                }
            }
        }
        return null;
    }

    /**
     * Build an SDP offer/answer for one selected codec.
     */
    public static function buildLocalSdp(
        string $localIp,
        int    $localRtpPort,
        array  $codec,
        ?array $telephoneEvent = null,
        ?array $opusConfig = null,
        ?int $ptime = null,
        bool $isAnswer = false,
    ): string
    {
        $ts = time();
        $pt = (int)$codec['pt'];
        $name = $codec['name'];
        $rate = (int)$codec['rate'];
        $isOpus = strcasecmp((string)$name, 'opus') === 0;
        $channels = $isOpus ? OpusConfig::RTP_CHANNELS : (int)($codec['channels'] ?? 1);

        $dtmfPt = (int)($telephoneEvent['pt'] ?? 101);
        $dtmfRate = (int)($telephoneEvent['rate'] ?? ($isOpus ? OpusConfig::RTP_RATE : 8000));
        $dtmfFmtp = trim((string)($telephoneEvent['fmtp'] ?? '0-15')) ?: '0-15';
        $includeDtmf = !$isAnswer || $telephoneEvent !== null;
        $effectivePtime = $ptime ?? ($isOpus ? (int)OpusConfig::normalize($opusConfig)['ptime'] : 20);

        $codecLine = "rtpmap:{$pt} {$name}/{$rate}";
        if ($channels > 1) $codecLine .= "/{$channels}";

        $sdp = "v=0\r\n";
        $sdp .= "o=- {$ts} 0 IN IP4 {$localIp}\r\n";
        $sdp .= "s=SpechPhone\r\n";
        $sdp .= "c=IN IP4 {$localIp}\r\n";
        $sdp .= "t=0 0\r\n";
        $sdp .= "m=audio {$localRtpPort} RTP/AVP {$pt}" . ($includeDtmf ? " {$dtmfPt}" : '') . "\r\n";
        $sdp .= "a={$codecLine}\r\n";
        if ($isOpus) {
            $sdp .= "a=fmtp:{$pt} " . OpusConfig::buildFmtp($opusConfig ?? []) . "\r\n";
        } elseif (!empty($codec['fmtp'])) {
            $sdp .= "a=fmtp:{$pt} {$codec['fmtp']}\r\n";
        }
        if ($includeDtmf) {
            $sdp .= "a=rtpmap:{$dtmfPt} telephone-event/{$dtmfRate}\r\n";
            $sdp .= "a=fmtp:{$dtmfPt} {$dtmfFmtp}\r\n";
        }
        $sdp .= "a=sendrecv\r\n";
        $sdp .= "a=ptime:{$effectivePtime}\r\n";

        return $sdp;
    }
}
