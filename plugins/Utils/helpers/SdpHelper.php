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
     * Returns ['ip', 'port', 'codecs', 'telephone_event'].
     */
    public static function parseRemoteSdp(array $sdp): array
    {
        $result = [
            'ip' => '',
            'port' => 0,
            'codecs' => [],
            'telephone_event' => null,
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
            $aLine = trim($aLine);
            if (str_starts_with($aLine, 'rtpmap:')) {
                $rest = substr($aLine, 7);
                [$pt, $codecStr] = array_pad(explode(' ', $rest, 2), 2, '');
                $pt = (int)$pt;
                $codecParts = explode('/', $codecStr);
                $rtpmap[$pt] = [
                    'name' => $codecParts[0],
                    'rate' => (int)($codecParts[1] ?? 8000),
                    'channels' => (int)($codecParts[2] ?? 1),
                ];
            } elseif (str_starts_with($aLine, 'fmtp:')) {
                $rest = substr($aLine, 5);
                [$pt, $params] = array_pad(explode(' ', $rest, 2), 2, '');
                $fmtp[(int)$pt] = $params;
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
                $result['telephone_event'] = [
                    'pt' => $pt,
                    'rate' => $info['rate'],
                    'fmtp' => $fmtp[$pt] ?? '0-15',
                ];
                continue;
            }

            $result['codecs'][] = [
                'pt' => $pt,
                'name' => $info['name'],
                'rate' => $info['rate'],
                'channels' => $info['channels'],
                'fmtp' => $fmtp[$pt] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Choose the best codec from a list using the priority order.
     */
    public static function chooseCodec(array $remoteCodecs): ?array
    {
        foreach (self::$codecPriority as $preferred) {
            foreach ($remoteCodecs as $remote) {
                if (strcasecmp($remote['name'], $preferred['name']) === 0) {
                    return [
                        'pt' => $preferred['pt'] ?? $remote['pt'],
                        'name' => $remote['name'],
                        'rate' => $remote['rate'],
                        'channels' => $remote['channels'],
                        'fmtp' => $remote['fmtp'] ?? null,
                    ];
                }
            }
        }
        return null;
    }

    /**
     * Build a minimal SDP answer string.
     */
    public static function buildLocalSdp(
        string $localIp,
        int    $localRtpPort,
        array  $codec,
        ?array $telephoneEvent = null
    ): string
    {
        $ts = time();
        $pt = (int)$codec['pt'];
        $name = $codec['name'];
        $rate = (int)$codec['rate'];
        $channels = (int)($codec['channels'] ?? 1);

        $dtmfPt = (int)($telephoneEvent['pt'] ?? 101);
        $dtmfRate = (int)($telephoneEvent['rate'] ?? 8000);

        $codecLine = "rtpmap:{$pt} {$name}/{$rate}";
        if ($channels > 1) $codecLine .= "/{$channels}";

        $sdp = "v=0\r\n";
        $sdp .= "o=- {$ts} 0 IN IP4 {$localIp}\r\n";
        $sdp .= "s=SpechPhone\r\n";
        $sdp .= "c=IN IP4 {$localIp}\r\n";
        $sdp .= "t=0 0\r\n";
        $sdp .= "m=audio {$localRtpPort} RTP/AVP {$pt} {$dtmfPt}\r\n";
        $sdp .= "a={$codecLine}\r\n";
        if (!empty($codec['fmtp'])) {
            $sdp .= "a=fmtp:{$pt} {$codec['fmtp']}\r\n";
        }
        $sdp .= "a=rtpmap:{$dtmfPt} telephone-event/{$dtmfRate}\r\n";
        $sdp .= "a=fmtp:{$dtmfPt} 0-15\r\n";
        $sdp .= "a=sendrecv\r\n";
        $sdp .= "a=ptime:20\r\n";

        return $sdp;
    }
}
