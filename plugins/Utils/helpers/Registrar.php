<?php

namespace helpers\utils;

use handlers\saveConfig;
use libspech\Cli\cli;
use libspech\Network\network;
use libspech\Sip\sip;
use libspech\Sip\trunkController;
use Swoole\Table;
use Swoole\WebSocket\Server;

class Registrar
{
    public const EXPIRES = 1800;
    public const REFRESH_THRESHOLD = 300;
    public const TICK_INTERVAL_MS = 30000;
    public const VAULT_PATH = '/data/spechphone/devices.vault';

    public static ?Table $states = null;

    public static function init(): void
    {
        $t = new Table(512);
        $t->column('fp', Table::TYPE_STRING, 128);
        $t->column('sip_user', Table::TYPE_STRING, 128);
        $t->column('sip_server', Table::TYPE_STRING, 256);
        $t->column('sip_domain', Table::TYPE_STRING, 256);
        $t->column('expires_at', Table::TYPE_INT);
        $t->column('last_registered_at', Table::TYPE_INT);
        $t->column('last_attempt_at', Table::TYPE_INT);
        $t->column('status', Table::TYPE_STRING, 32);
        $t->column('failures', Table::TYPE_INT);
        $t->create();
        self::$states = $t;
    }

    public static function bootstrap(Server $server): void
    {
        go(function () use ($server) {
            self::tick($server, true);
        });
    }

    public static function tick(Server $server, bool $force = false): void
    {
        if (self::$states === null) return;

        try {
            $vault = new \spechphoneVault(self::VAULT_PATH, getenv('SPECH_VAULT_KEY_HEX'));
        } catch (\Throwable $e) {
            cli::pcl('[REGISTRAR] Falha ao abrir vault: ' . $e->getMessage(), 'red');
            return;
        }

        $now = time();
        foreach ($vault->keys() as $fp) {
            $data = $vault->get($fp);
            if (!self::hasCredentials($data)) continue;

            $row = self::$states->get($fp);
            $expiresAt = $row ? (int)$row['expires_at'] : 0;
            $isRegistered = $row && $row['status'] === 'registered';

            if (!$force && $isRegistered && ($expiresAt - $now) > self::REFRESH_THRESHOLD) {
                continue;
            }

            go(fn() => self::registerOne($server, $fp, $data));
        }
    }

    private static function hasCredentials(?array $data): bool
    {
        if (!$data) return false;
        foreach (['sipServer', 'sipUser', 'sipPass'] as $k) {
            if (empty($data[$k])) return false;
        }
        return true;
    }

    public static function registerOne(Server $server, string $fp, array $data): bool
    {
        $sipServer = saveConfig::parseSipServer($data['sipServer']);

        $data['sipServer'] = $sipServer;
        $sipServer = $data['sipServer'];
        $sipUser = $data['sipUser'];
        $sipPass = $data['sipPass'];
        $sipDomain = !empty($data['sipDomain']) ? $data['sipDomain'] : $sipServer;

        try {
            $phone = new trunkController($sipUser, $sipPass, $sipServer);
        } catch (\Throwable $e) {
            self::recordFailure($fp, $sipUser, $sipServer, $sipDomain);
            cli::pcl("[REGISTRAR] Falha ao instanciar trunkController para {$sipUser}@{$sipServer}: " . $e->getMessage(), 'red');
            return false;
        }

        $modelRegister = $phone->modelRegister(self::EXPIRES);
        $modelRegister['headers']['Via'][] = "SIP/2.0/UDP " . network::getLocalIp() . ":{$phone->socketPortListen};branch=z9hG4bK{$phone->callId};rport";

        $server->sendto($phone->host, $phone->port, sip::renderSolution($modelRegister));

        $authenticated = false;
        for ($n = 4; $n--;) {
            $peer = [];
            $res = $phone->socket->recvfrom($peer, 5);
            if ($res === false || $res === '') continue;

            $receive = sip::parse($res);
            $code = (string)($receive['method'] ?? '');

            if ($code === '401' || $code === '407') {
                $hKey = $code === '407' ? 'Proxy-Authenticate' : 'WWW-Authenticate';
                $aKey = $code === '407' ? 'Proxy-Authorization' : 'Authorization';
                $hVal = $receive['headers'][$hKey][0] ?? '';
                if ($hVal === '') break;
                $nonce = value($hVal, 'nonce="', '"');
                $realm = value($hVal, 'realm="', '"');
                $auth = sip::generateAuthorizationHeader($phone->username, $realm, $phone->password, $nonce, 'sip:' . $phone->host, 'REGISTER');
                $modelRegister['headers'][$aKey][0] = $auth;
                $server->sendto($phone->host, $phone->port, sip::renderSolution($modelRegister));
                continue;
            }

            if ($code === '200') {
                $authenticated = true;
                break;
            }

            cli::pcl("[REGISTRAR] {$sipUser}@{$sipServer} resposta inesperada: {$code}", 'red');
            break;
        }
        $phone->close();

        $now = time();
        if ($authenticated) {
            self::$states->set($fp, [
                'fp' => $fp,
                'sip_user' => $sipUser,
                'sip_server' => $sipServer,
                'sip_domain' => $sipDomain,
                'expires_at' => $now + self::EXPIRES,
                'last_registered_at' => $now,
                'last_attempt_at' => $now,
                'status' => 'registered',
                'failures' => 0,
            ]);
            if (CallState::$sipBindings !== null) {
                CallState::$sipBindings->set($sipUser, [
                    'fp' => $fp,
                    'sip_user' => $sipUser,
                    'sip_server' => $sipServer,
                    'sip_domain' => $sipDomain,
                    'contact_port' => 4000,
                    'registered_at' => $now,
                    'expires_at' => $now + self::EXPIRES,
                ]);
            }
            cli::pcl("[REGISTRAR] {$sipUser}@{$sipServer} registrado (fp:{$fp}) expira em " . self::EXPIRES . 's', 'green');
            return true;
        }

        self::recordFailure($fp, $sipUser, $sipServer, $sipDomain);
        cli::pcl("[REGISTRAR] Falha ao registrar {$sipUser}@{$sipServer} $sipPass (fp:{$fp})", 'red');
        WebPushHelper::notifyUser($sipUser, [
            'message' => "Falha ao registrar sua conta, verifique os dados fornecidos!"
        ]);
        return false;
    }

    private static function recordFailure(string $fp, string $sipUser, string $sipServer, string $sipDomain): void
    {
        if (self::$states === null) return;
        $now = time();
        $current = self::$states->get($fp) ?: [];
        $failures = (int)($current['failures'] ?? 0) + 1;
        self::$states->set($fp, [
            'fp' => $fp,
            'sip_user' => $sipUser,
            'sip_server' => $sipServer,
            'sip_domain' => $sipDomain,
            'expires_at' => (int)($current['expires_at'] ?? 0),
            'last_registered_at' => (int)($current['last_registered_at'] ?? 0),
            'last_attempt_at' => $now,
            'status' => 'failed',
            'failures' => $failures,
        ]);
    }
}
