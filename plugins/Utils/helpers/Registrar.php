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
    public const MAX_AUTOMATIC_FAILURES = 3;
    public const BACKOFF_SECONDS = [60, 120];
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
        $t->column('next_retry_at', Table::TYPE_INT);
        $t->column('config_hash', Table::TYPE_STRING, 128);
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
            $vault = new \spechphoneVault(AccountIdentity::vaultPath(), getenv('SPECH_VAULT_KEY_HEX'));
        } catch (\Throwable $e) {
            cli::pcl('[REGISTRAR] Falha ao abrir vault: ' . $e->getMessage(), 'red');
            return;
        }

        $now = time();
        foreach ($vault->keys() as $fp) {
            $data = $vault->get($fp);
            if (!self::hasCredentials($data)) continue;

            $row = self::$states->get($fp);
            if (!self::automaticAttemptAllowed($fp, $data)) continue;
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
        // Registration failures belong to SIP state, not to Web Push messages.
        // Never send a Push notification from the automatic retry loop.
        return (bool)$result['success'];
    }

    /**
     * Single registration entry point used by setup, frontend requests,
     * reconnect and the background renewal loop.
     *
     * @return array<string,mixed>
     */
    public static function registerOneDetailed(Server $server, string $fp, array $data, bool $explicit = false): array
    {
        if (self::$states === null) self::init();
        try {
            $lock = SipRegistrationLock::acquire($fp);
            if ($lock === false) {
                return ['success' => false, 'reason' => 'registration_in_progress', 'code' => null];
            }
        } catch (\Throwable $e) {
            return ['success' => false, 'reason' => 'registration_lock_unavailable', 'code' => null,
                'detail' => $e->getMessage()];
        }
        try {
            return self::registerOneWhileLocked($server, $fp, $data, $explicit);
        } finally {
            SipRegistrationLock::release($lock);
        }
    }

    /** @return array<string,mixed> */
    private static function registerOneWhileLocked(Server $server, string $fp, array $data, bool $explicit): array
    {
        if (!$explicit && !self::automaticAttemptAllowed($fp, $data)) {
            $row = self::$states?->get($fp) ?: [];
            $reason = ($row['status'] ?? '') === 'paused' ? 'retry_suspended' : 'retry_backoff';
            return ['success' => false, 'reason' => $reason, 'code' => null,
                'next_retry_at' => (int)($row['next_retry_at'] ?? 0)];
        }
        if ($explicit) self::$states?->del($fp);

        // The Contact URI must identify the local account even for vault rows
        // created before accountId/fp became explicit fields.
        $data['accountId'] = $fp;
        $data['fp'] = $fp;

        $sipUser = trim((string)($data['sipUser'] ?? ''));
        $sipServer = trim((string)($data['sipServer'] ?? ''));
        $sipDomain = trim((string)($data['sipDomain'] ?? '')) ?: $sipServer;
        $needsLegacyContactCleanup = (int)($data['contactIdentityVersion'] ?? 0) < 2;
        $legacyContactRemoved = !$needsLegacyContactCleanup;
        if ($needsLegacyContactCleanup) {
            $legacyData = $data;
            unset($legacyData['accountId'], $legacyData['fp']);
            $cleanup = SipRegisterManager::register($server, $legacyData, 0, 5.0);
            $legacyContactRemoved = (bool)$cleanup['success'];
            cli::pcl(
                $legacyContactRemoved
                    ? "[REGISTRAR] Contact legado removido accountId={$fp}"
                    : "[REGISTRAR] não foi possível remover Contact legado accountId={$fp} reason=" . ($cleanup['reason'] ?? 'unknown'),
                $legacyContactRemoved ? 'green' : 'yellow'
            );
        }
        $result = SipRegisterManager::register($server, $data, self::EXPIRES);
        $result['legacy_contact_removed'] = $legacyContactRemoved;

        if ($result['success']) {
            if ($legacyContactRemoved) self::markContactIdentityMigrated($fp);
            self::recordSuccess($fp, $sipUser, $sipServer, $sipDomain, $data);
            cli::pcl(
                "[REGISTRAR] {$sipUser}@{$sipServer} registrado via UDP :" . SipRegisterManager::SIP_PORT
                . " (fp:{$fp}) expira em " . self::EXPIRES . 's',
                'green'
            );
            cli::pcl(
                '[REGISTRAR] Contact accountId=' . $fp
                . ' user=' . AccountIdentity::contactUser($fp)
                . ' confirmed=' . (!empty($result['binding_confirmed']) ? 'yes' : 'no'),
                !empty($result['binding_confirmed']) ? 'green' : 'yellow'
            );
            return $result;
        }

        if ($result['reason'] !== 'registration_in_progress') {
            self::recordFailure($fp, $sipUser, $sipServer, $sipDomain, $data, (string)$result['reason'],
                isset($result['retry_after']) ? (int)$result['retry_after'] : null,
                isset($result['code']) ? (int)$result['code'] : null);
            cli::pcl(
                "[REGISTRAR] falha {$sipUser}@{$sipServer} (fp:{$fp}) motivo:{$result['reason']}"
                . ($result['code'] !== null ? " SIP:{$result['code']}" : ''),
                'red'
            );
        }
        return $result;
    }

    private static function markContactIdentityMigrated(string $fp): void
    {
        try {
            $vault = new \spechphoneVault(AccountIdentity::vaultPath(), (string)getenv('SPECH_VAULT_KEY_HEX'));
            if (!$vault->exists($fp)) return;
            $current = $vault->get($fp);
            if (!is_array($current)) return;
            $current['accountId'] = $fp;
            $current['fp'] = $fp;
            $current['contactIdentityVersion'] = 2;
            $vault->set($fp, $current);
            $vault->flush();
        } catch (\Throwable $e) {
            cli::pcl("[REGISTRAR] falha ao persistir migração de Contact accountId={$fp}", 'yellow');
        }
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
            'retry_backoff' => 'Nova tentativa SIP aguardando intervalo de recuperação.',
            'retry_suspended' => 'Registro automático suspenso após falhas. Verifique a configuração e tente registrar novamente.',
            'unsupported_challenge' => 'O servidor SIP solicitou um método de autenticação não suportado.',
            'invalid_configuration' => 'Preencha servidor, usuário e senha SIP.',
            'sip_error' => 'O servidor SIP recusou o registro (código ' . ($result['code'] ?? 'desconhecido') . ').',
            default => 'Não foi possível confirmar o registro SIP.',
        };
    }

    private static function recordSuccess(string $fp, string $sipUser, string $sipServer, string $sipDomain, array $data): void
    {
        if (self::$states === null) return;
        self::removeDurablePause($fp);
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
            'next_retry_at' => 0,
            'config_hash' => self::configurationHash($data),
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

    private static function configurationHash(array $data): string
    {
        // Never store credentials in the state table; only their fingerprint.
        return hash('sha256', implode("\0", array_map(
            static fn(string $key): string => (string)($data[$key] ?? ''),
            ['sipServer', 'sipDomain', 'sipUser', 'sipPass', 'registrarHost', 'registrarPort']
        )));
    }

    public static function automaticAttemptAllowed(string $fp, array $data): bool
    {
        $row = self::$states?->get($fp);
        try {
            $durablePause = self::readDurablePause($fp);
        } catch (\Throwable $e) {
            cli::pcl('[REGISTRAR] Estado de suspensão ilegível; bloqueando nova tentativa: ' . $e->getMessage(), 'red');
            return false;
        }
        $configHash = self::configurationHash($data);
        $pauseExpired = false;
        if ($durablePause !== null) {
            if (hash_equals($durablePause['config_hash'], $configHash)
                && ($durablePause['expires_at'] === 0 || $durablePause['expires_at'] > time())) {
                return false;
            }
            $pauseExpired = $durablePause['expires_at'] > 0 && $durablePause['expires_at'] <= time();
            self::removeDurablePause($fp);
        }
        if (!$row) return true;
        if (!hash_equals((string)($row['config_hash'] ?? ''), $configHash)) {
            return true; // A new configuration should be retried immediately.
        }
        if (($row['status'] ?? '') === 'paused') {
            if (!$pauseExpired) return false;
            self::$states?->set($fp, [
                'fp' => $row['fp'], 'sip_user' => $row['sip_user'], 'sip_server' => $row['sip_server'],
                'sip_domain' => $row['sip_domain'], 'expires_at' => $row['expires_at'],
                'last_registered_at' => $row['last_registered_at'], 'last_attempt_at' => $row['last_attempt_at'],
                'status' => 'failed', 'failures' => $row['failures'], 'next_retry_at' => time(),
                'config_hash' => $row['config_hash'],
            ]);
            return true;
        }
        return (int)($row['next_retry_at'] ?? 0) <= time();
    }

    private static function recordFailure(string $fp, string $sipUser, string $sipServer, string $sipDomain, array $data, string $reason, ?int $retryAfter = null, ?int $code = null): void
    {
        if (self::$states === null) return;
        $now = time();
        $current = self::$states->get($fp) ?: [];
        $sameConfiguration = (string)($current['config_hash'] ?? '') === self::configurationHash($data);
        $failures = ($sameConfiguration ? (int)($current['failures'] ?? 0) : 0) + 1;
        $permanent = in_array($reason, ['authentication_failed', 'unsupported_challenge',
            'invalid_configuration'], true) || ($reason === 'sip_error' && in_array($code, [400, 404, 405, 410, 416, 484], true));
        $pause = $permanent || $failures >= self::MAX_AUTOMATIC_FAILURES;
        $backoff = $retryAfter ?? self::BACKOFF_SECONDS[min($failures - 1, count(self::BACKOFF_SECONDS) - 1)];
        self::$states->set($fp, [
            'fp' => $fp,
            'sip_user' => $sipUser,
            'sip_server' => $sipServer,
            'sip_domain' => $sipDomain,
            'expires_at' => (int)($current['expires_at'] ?? 0),
            'last_registered_at' => (int)($current['last_registered_at'] ?? 0),
            'last_attempt_at' => $now,
            'status' => $pause ? 'paused' : 'failed',
            'failures' => $failures,
            'next_retry_at' => $pause ? 0 : $now + $backoff,
            'config_hash' => self::configurationHash($data),
        ]);
        if ($pause) {
            // Permanent authentication/configuration failures stay suspended
            // until configuration changes. Temporary exhaustion expires after
            // one day, allowing one controlled recovery probe after an outage.
            self::persistPause($fp, self::configurationHash($data), $permanent ? 0 : $now + 86400);
        }
        if (CallState::$sipBindings !== null && $sipUser !== '') {
            foreach (CallState::$sipBindings as $bindingKey => $binding) {
                if ($binding['fp'] === $fp && $binding['sip_user'] === $sipUser) {
                    CallState::$sipBindings->del((string)$bindingKey);
                }
            }
        }
    }

    private static function durablePauseFile(): string
    {
        return DataPath::file('sip_registration_pauses.json');
    }

    /** @return array{config_hash:string,expires_at:int}|null */
    private static function readDurablePause(string $fp): ?array
    {
        $path = self::durablePauseFile();
        if (!is_file($path)) return null;
        $handle = fopen($path, 'r');
        if ($handle === false) throw new \RuntimeException('cannot read pause state');
        try {
            if (!flock($handle, LOCK_SH)) throw new \RuntimeException('cannot lock pause state');
            $contents = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        } finally { fclose($handle); }
        try { $entries = json_decode($contents ?: '{}', true, 512, JSON_THROW_ON_ERROR); }
        catch (\Throwable $e) { throw new \RuntimeException('pause state is corrupt', 0, $e); }
        $entry = is_array($entries) ? ($entries[$fp] ?? null) : null;
        return is_array($entry) && isset($entry['config_hash'], $entry['expires_at'])
            ? ['config_hash' => (string)$entry['config_hash'], 'expires_at' => (int)$entry['expires_at']]
            : null;
    }

    private static function persistPause(string $fp, string $configHash, int $expiresAt): void
    {
        try {
            $path = self::durablePauseFile();
            $handle = fopen($path, 'c+');
            if ($handle === false || !flock($handle, LOCK_EX)) throw new \RuntimeException('pause lock unavailable');
            rewind($handle);
            $entries = json_decode(stream_get_contents($handle) ?: '{}', true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($entries)) $entries = [];
            $entries[$fp] = ['config_hash' => $configHash, 'expires_at' => $expiresAt];
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($entries, JSON_THROW_ON_ERROR));
            fflush($handle);
            @chmod($path, 0600);
            flock($handle, LOCK_UN);
            fclose($handle);
        } catch (\Throwable $e) {
            cli::pcl('[REGISTRAR] Não foi possível persistir suspensão: ' . $e->getMessage(), 'red');
            throw $e;
        }
    }

    private static function removeDurablePause(string $fp): void
    {
        try {
            $path = self::durablePauseFile();
            if (!is_file($path)) return;
            $handle = fopen($path, 'c+');
            if ($handle === false || !flock($handle, LOCK_EX)) return;
            rewind($handle);
            $entries = json_decode(stream_get_contents($handle) ?: '{}', true, 512, JSON_THROW_ON_ERROR);
            if (is_array($entries) && array_key_exists($fp, $entries)) {
                unset($entries[$fp]);
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, json_encode($entries, JSON_THROW_ON_ERROR));
                fflush($handle);
            }
            flock($handle, LOCK_UN);
            fclose($handle);
        } catch (\Throwable) { /* A stale hash is invalidated on the next check. */ }
    }
}
