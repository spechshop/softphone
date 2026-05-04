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

    public static function findFpBySipUser(string $sipUser): ?string
    {
        if (self::$sipBindings === null) return null;
        foreach (self::$sipBindings as $row) {
            if ($row['sip_user'] === $sipUser) {
                return $row['fp'];
            }
        }
        return null;
    }

    public static function hasActiveCallForFp(string $fp): bool
    {
        if (self::$incomingCalls === null) return false;
        foreach (self::$incomingCalls as $row) {
            if ($row['fp'] === $fp && in_array($row['status'], ['ringing', 'accepted', 'active'], true)) {
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
            if ($row['fp'] === $fp && in_array($row['status'], ['ringing', 'accepted', 'active'], true)) {
                return $row;
            }
        }
        return null;
    }
}
