<?php

namespace helpers\utils;

use libspech\Cli\cli;
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
        if (self::$states !== null) return;
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
        $result = self::registerOneDetailed($server, $fp, $data);
        if (!$result['success'] && $result['reason'] !== 'registration_in_progress') {
            go(fn() => WebPushHelper::notifyUser($fp, [
                'message' => self::messageForResult($result),
            ]));
        }
        return (bool)$result['success'];
    }

    /**
     * Single registration entry point used by setup, frontend requests,
     * reconnect and the background renewal loop.
     *
     * @return array<string,mixed>
     */
    public static function registerOneDetailed(Server $server, string $fp, array $data): array
    {
        if (self::$states === null) self::init();

        // The Contact URI must identify the local account even for vault rows
        // created before accountId/fp became explicit fields.
        $data['accountId'] = $fp;
        $data['fp'] = $fp;

        $sipUser = trim((string)($data['sipUser'] ?? ''));
        $sipServer = trim((string)($data['sipServer'] ?? ''));
        $sipDomain = trim((string)($data['sipDomain'] ?? '')) ?: $sipServer;
        $result = SipRegisterManager::register($server, $data, self::EXPIRES);

        if ($result['success']) {
            self::recordSuccess($fp, $sipUser, $sipServer, $sipDomain);
            cli::pcl(
                "[REGISTRAR] {$sipUser}@{$sipServer} registrado via UDP :" . SipRegisterManager::SIP_PORT
                . " (fp:{$fp}) expira em " . self::EXPIRES . 's',
                'green'
            );
            return $result;
        }

        if ($result['reason'] !== 'registration_in_progress') {
            self::recordFailure($fp, $sipUser, $sipServer, $sipDomain);
            cli::pcl(
                "[REGISTRAR] falha {$sipUser}@{$sipServer} (fp:{$fp}) motivo:{$result['reason']}"
                . ($result['code'] !== null ? " SIP:{$result['code']}" : ''),
                'red'
            );
        }
        return $result;
    }

    /** @param array<string,mixed> $result */
    public static function messageForResult(array $result): string
    {
        return match ($result['reason'] ?? '') {
            'authentication_failed' => 'Falha de autenticação SIP. Verifique usuário e senha.',
            'timeout' => 'O servidor SIP não respondeu dentro do tempo limite.',
            'host_resolution_failed' => 'Não foi possível resolver o servidor SIP informado.',
            'nat_port_mismatch' => 'O NAT não preservou a porta SIP 4000; revise o redirecionamento UDP.',
            'registration_in_progress' => 'Já existe uma tentativa de registro em andamento.',
            'unsupported_challenge' => 'O servidor SIP solicitou um método de autenticação não suportado.',
            'invalid_configuration' => 'Preencha servidor, usuário e senha SIP.',
            'sip_error' => 'O servidor SIP recusou o registro (código ' . ($result['code'] ?? 'desconhecido') . ').',
            default => 'Não foi possível confirmar o registro SIP.',
        };
    }

    private static function recordSuccess(string $fp, string $sipUser, string $sipServer, string $sipDomain): void
    {
        if (self::$states === null) return;
        $now = time();
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
            $newBindingKey = PhoneController::accountKey([
                'sipUser' => $sipUser, 'sipServer' => $sipServer, 'sipDomain' => $sipDomain,
            ]);
            foreach (CallState::$sipBindings as $existingBindingKey => $binding) {
                $existingKey = (string)$existingBindingKey;
                if ($binding['fp'] === $fp && $existingKey !== $newBindingKey) {
                    CallState::$sipBindings->del($existingKey);
                }
            }
            CallState::$sipBindings->set($newBindingKey, [
                'fp' => $fp,
                'sip_user' => $sipUser,
                'sip_server' => $sipServer,
                'sip_domain' => $sipDomain,
                'contact_port' => SipRegisterManager::SIP_PORT,
                'registered_at' => $now,
                'expires_at' => $now + self::EXPIRES,
            ]);
        }
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
        if (CallState::$sipBindings !== null && $sipUser !== '') {
            foreach (CallState::$sipBindings as $bindingKey => $binding) {
                if ($binding['fp'] === $fp && $binding['sip_user'] === $sipUser) {
                    CallState::$sipBindings->del((string)$bindingKey);
                }
            }
        }
    }
}
