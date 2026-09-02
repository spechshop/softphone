<?php

namespace helpers\utils;

use Swoole\Table;

class CallState
{
    public static ?Table $incomingCalls = null;
    public static ?Table $activeCalls = null;
    public static ?Table $sipBindings = null;

    public static function init(): void
    {
        $incomingCalls = new Table(512);
        $incomingCalls->column('call_id', Table::TYPE_STRING, 128);
        $incomingCalls->column('fp', Table::TYPE_STRING, 128);
        $incomingCalls->column('status', Table::TYPE_STRING, 32);
        $incomingCalls->column('from_uri', Table::TYPE_STRING, 256);
        $incomingCalls->column('to_uri', Table::TYPE_STRING, 256);
        $incomingCalls->column('remote_ip', Table::TYPE_STRING, 64);
        $incomingCalls->column('remote_port', Table::TYPE_INT);
        $incomingCalls->column('remote_rtp_ip', Table::TYPE_STRING, 64);
        $incomingCalls->column('remote_rtp_port', Table::TYPE_INT);
        $incomingCalls->column('local_rtp_port', Table::TYPE_INT);
        $incomingCalls->column('codec', Table::TYPE_STRING, 64);
        $incomingCalls->column('frequency', Table::TYPE_INT);
        $incomingCalls->column('owner_worker_id', Table::TYPE_INT);
        $incomingCalls->column('invite_headers_json', Table::TYPE_STRING, 8192);
        $incomingCalls->column('invite_sdp_json', Table::TYPE_STRING, 4096);
        $incomingCalls->column('tx_key', Table::TYPE_STRING, 64);
        $incomingCalls->column('to_tag', Table::TYPE_STRING, 64);
        $incomingCalls->column('from_tag', Table::TYPE_STRING, 64);
        $incomingCalls->column('invite_cseq', Table::TYPE_STRING, 32);
        $incomingCalls->column('last_response_code', Table::TYPE_INT);
        $incomingCalls->column('created_at', Table::TYPE_INT);
        $incomingCalls->column('updated_at', Table::TYPE_INT);
        $incomingCalls->create();
        self::$incomingCalls = $incomingCalls;

        $activeCalls = new Table(256);
        $activeCalls->column('call_id', Table::TYPE_STRING, 128);
        $activeCalls->column('fp', Table::TYPE_STRING, 128);
        $activeCalls->column('status', Table::TYPE_STRING, 32);
        $activeCalls->column('owner_worker_id', Table::TYPE_INT);
        $activeCalls->column('local_rtp_port', Table::TYPE_INT);
        $activeCalls->column('remote_rtp_ip', Table::TYPE_STRING, 64);
        $activeCalls->column('remote_rtp_port', Table::TYPE_INT);
        $activeCalls->column('codec', Table::TYPE_STRING, 64);
        $activeCalls->column('frequency', Table::TYPE_INT);
        $activeCalls->column('created_at', Table::TYPE_INT);
        $activeCalls->column('updated_at', Table::TYPE_INT);
        $activeCalls->create();
        self::$activeCalls = $activeCalls;

        $sipBindings = new Table(512);
        $sipBindings->column('fp', Table::TYPE_STRING, 128);
        $sipBindings->column('sip_user', Table::TYPE_STRING, 128);
        $sipBindings->column('sip_server', Table::TYPE_STRING, 256);
        $sipBindings->column('sip_domain', Table::TYPE_STRING, 256);
        $sipBindings->column('contact_port', Table::TYPE_INT);
        $sipBindings->column('registered_at', Table::TYPE_INT);
        $sipBindings->column('expires_at', Table::TYPE_INT);
        $sipBindings->create();
        self::$sipBindings = $sipBindings;
    }

    public static function findFpForInbound(string $sipUser, string $sipDomain): ?string
    {
        return self::findRegisteredFpForInbound($sipUser, $sipDomain, '');
    }

    public static function findRegisteredFpForInbound(string $sipUser, string $sipDomain, string $sourceHost = ''): ?string
    {
        if (self::$sipBindings === null || (trim($sipDomain) === '' && trim($sourceHost) === '')) return null;
        $rows = [];
        foreach (self::$sipBindings as $row) {
            if ((int)($row['expires_at'] ?? 0) <= time()) continue;
            if (strcasecmp((string)$row['sip_user'], $sipUser) !== 0) continue;
            $serverHost = AccountIdentity::host((string)$row['sip_server']);
            $rows[] = ['row' => $row, 'server_host' => $serverHost];
        }
        $candidates = $rows;
        $identityMatched = false;
        if ($sipDomain !== '') {
            $domainMatches = array_values(array_filter($rows, static fn(array $entry): bool =>
                strcasecmp((string)$entry['row']['sip_domain'], $sipDomain) === 0
                || strcasecmp($entry['server_host'], $sipDomain) === 0
            ));
            if ($domainMatches) {
                $candidates = $domainMatches;
                $identityMatched = true;
            }
        }
        if ($sourceHost !== '' && (count($candidates) > 1 || !$identityMatched)) {
            $sourceMatches = array_values(array_filter($candidates, static function (array $entry) use ($sourceHost): bool {
                $serverHost = $entry['server_host'];
                if (strcasecmp($serverHost, $sourceHost) === 0) return true;
                return filter_var($sourceHost, FILTER_VALIDATE_IP) && !filter_var($serverHost, FILTER_VALIDATE_IP)
                    && in_array($sourceHost, gethostbynamel($serverHost) ?: [], true);
            }));
            if ($sourceMatches) {
                $candidates = $sourceMatches;
                $identityMatched = true;
            }
        }
        if (!$identityMatched) return null;
        $matches = [];
        foreach ($candidates as $entry) $matches[(string)$entry['row']['fp']] = true;
        return count($matches) === 1 ? (string)array_key_first($matches) : null;
    }

    public static function hasActiveCallForFp(string $fp, ?string $exceptCallId = null): bool
    {
        if (self::$incomingCalls === null) return false;
        foreach (self::$incomingCalls as $row) {
            if ($exceptCallId !== null && $row['call_id'] === $exceptCallId) continue;
            if ($row['fp'] === $fp && in_array($row['status'], ['pending_user', 'ringing', 'accepted', 'active'], true)) {
                return true;
            }
        }
        return false;
    }

    /** Alias used by hangUpCall — same logic, clearer name. */
    public static function findIncomingCallForHangup(string $fp): ?array
    {
        return self::findIncomingCallByFp($fp);
    }

    public static function findIncomingCallByFp(string $fp): ?array
    {
        if (self::$incomingCalls === null) return null;
        foreach (self::$incomingCalls as $row) {
            if ($row['fp'] === $fp && in_array($row['status'], ['pending_user', 'ringing', 'accepted', 'active'], true)) {
                return $row;
            }
        }
        return null;
    }
}
