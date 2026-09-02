<?php

namespace helpers\utils;

use libspech\Cli\cli;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushHelper
{
    private static ?string $subFile = null;
    private static bool $explicitFile = false;
    /** @var array<string,bool> */
    private static array $initializedFiles = [];

    public static function setFile(string $file): void
    {
        self::$subFile = $file;
        self::$explicitFile = true;
        unset(self::$initializedFiles[$file]);
    }

    public static function storageFile(): string
    {
        return self::$subFile ?? DataPath::file('push_subscriptions.json');
    }

    public static function subscriptionCountForAccount(string $accountId): int
    {
        return count(self::loadSubs()[$accountId] ?? []);
    }

    public static function saveSubscription(string $accountId, array $sub): void
    {
        if ($accountId === '' || empty($sub['endpoint'])) {
            cli::pcl('[PUSH:SAVE] ERRO: accountId/endpoint ausente', 'red');
            return;
        }
        $hash = hash('sha256', (string)$sub['endpoint']);
        $count = self::mutateSubs(function (array &$data) use ($accountId, $sub, $hash): int {
            $existing = $data[$accountId][$hash] ?? [];
            $data[$accountId][$hash] = [
                'endpoint' => $sub['endpoint'],
                'keys' => ['p256dh' => $sub['keys']['p256dh'] ?? '', 'auth' => $sub['keys']['auth'] ?? ''],
                'fp' => $accountId, 'created_at' => $existing['created_at'] ?? time(), 'updated_at' => time(),
            ];
            return count($data[$accountId]);
        });
        cli::pcl("[PUSH:SAVE] accountId={$accountId} subscriptions={$count}", 'green');
    }

    public static function removeSubscription(string $accountId, string $endpoint): void
    {
        $hash = hash('sha256', $endpoint);
        self::mutateSubs(function (array &$data) use ($accountId, $hash): void {
            $data = self::pruneExpiredData($data, $accountId, [$hash]);
        });
    }

    /**
     * Convert sipUser-keyed storage. Embedded fp wins; without fp, a legacy
     * bucket is migrated only when the username maps to exactly one account.
     * Ambiguous data remains quarantined and is never used for delivery.
     */
    public static function migrateLegacyData(array $data, array $accounts): array
    {
        $result = [];
        $unresolved = is_array($data['_legacy_unresolved'] ?? null) ? $data['_legacy_unresolved'] : [];
        foreach ($data as $legacyKey => $subscriptions) {
            if ($legacyKey === '_legacy_unresolved' || !is_array($subscriptions)) continue;
            foreach ($subscriptions as $hash => $sub) {
                if (!is_array($sub) || empty($sub['endpoint'])) continue;
                $target = null;
                $storedFp = (string)($sub['fp'] ?? '');
                if ($storedFp !== '') {
                    $target = $storedFp;
                } elseif (isset($accounts[$legacyKey])) {
                    $target = $legacyKey;
                } else {
                    $matches = array_keys(array_filter($accounts, static fn(array $a): bool =>
                        strcasecmp((string)($a['sipUser'] ?? ''), (string)$legacyKey) === 0
                    ));
                    if (count($matches) === 1) $target = $matches[0];
                }
                if ($target === null) {
                    $unresolved[$legacyKey][$hash] = $sub;
                    continue;
                }
                $sub['fp'] = $target;
                $result[$target][$hash] = $sub;
            }
        }
        if ($unresolved) $result['_legacy_unresolved'] = $unresolved;
        return $result;
    }

    public static function notifyUser(string $accountId, array $message): void
    {
        $data = self::loadSubs();
        $subscriptions = $data[$accountId] ?? [];
        if (!$subscriptions) {
            cli::pcl("[PUSH:NOTIFY] accountId={$accountId} sem subscription", 'yellow');
            return;
        }
        cli::pcl("[PUSH:MESSAGE] accountId={$accountId} subscriptions=" . count($subscriptions), 'cyan');
        $remoteUri = (string)($message['remoteUri'] ?? $message['fromUri'] ?? 'Contato');
        $body = mb_substr((string)($message['body'] ?? $message['message'] ?? ''), 0, 120);
        $payload = [
            'type' => 'message', 'title' => 'Nova mensagem', 'body' => "{$remoteUri}: {$body}",
            'tag' => 'spech-message-' . hash('sha1', $accountId . '|' . $remoteUri), 'url' => '/',
            'accountId' => $accountId, 'remoteUri' => $remoteUri, 'messageId' => $message['id'] ?? null,
        ];
        self::sendAll($data, $accountId, $subscriptions, $payload);
    }

    public static function notifyIncomingCall(string $accountId, array $call): void
    {
        $data = self::loadSubs();
        $subscriptions = $data[$accountId] ?? [];
        if (!$subscriptions) {
            cli::pcl("[PUSH:CALL] accountId={$accountId} sem subscription", 'yellow');
            return;
        }
        cli::pcl("[PUSH:CALL] accountId={$accountId} subscriptions=" . count($subscriptions), 'cyan');
        $fromUri = (string)($call['fromUri'] ?? $call['from'] ?? 'Desconhecido');
        $fromUser = \libspech\Sip\sip::extractURI($fromUri)['user'] ?? 'Desconhecido';
        $callId = (string)($call['callId'] ?? '');
        $payload = [
            'type' => 'call', 'title' => 'Chamada recebida', 'body' => "{$fromUser} está chamando",
            'tag' => 'spech-call-' . hash('sha1', $accountId . '|' . $callId), 'url' => '/',
            'accountId' => $accountId, 'fromUri' => $fromUri, 'callId' => $callId,
        ];
        self::sendAll($data, $accountId, $subscriptions, $payload);
    }

    private static function sendAll(array $data, string $accountId, array $subscriptions, array $payload): void
    {
        $expired = [];
        foreach ($subscriptions as $hash => $sub) {
            $result = self::send($sub, $payload);
            if ($result === true) cli::pcl("[PUSH:SEND] success accountId={$accountId}", 'green');
            if ($result === false) $expired[] = $hash;
        }
        if (!$expired) return;
        self::mutateSubs(function (array &$current) use ($accountId, $expired): void {
            $current = self::pruneExpiredData($current, $accountId, $expired);
        });
        cli::pcl('[PUSH] ' . count($expired) . " subscription(s) inválida(s) removida(s) accountId={$accountId}", 'yellow');
    }

    public static function pruneExpiredData(array $data, string $accountId, array $hashes): array
    {
        foreach ($hashes as $hash) unset($data[$accountId][$hash]);
        if (isset($data[$accountId]) && !$data[$accountId]) unset($data[$accountId]);
        return $data;
    }

    /** false means expired; null means a transient/configuration failure. */
    public static function send(array $subscription, array $payload): ?bool
    {
        $auth = self::buildAuth();
        if (!$auth) {
            cli::pcl('[PUSH:SEND] VAPID não configurado', 'red');
            return null;
        }
        try {
            set_error_handler(static fn(int $severity, string $message, string $file): bool =>
                $severity === E_DEPRECATED && str_contains($file, 'minishlink/web-push')
            );
            $caBundle = self::caBundlePath();
            $clientOptions = $caBundle !== null ? ['verify' => $caBundle] : [];
            if ($caBundle === null) {
                cli::pcl('[PUSH:TLS] bundle de CAs não encontrado; revise SPECH_PUSH_CA_BUNDLE/ca-certificates', 'red');
            }
            $webPush = new WebPush($auth, [], 30, $clientOptions);
            $sub = Subscription::create([
                'endpoint' => $subscription['endpoint'], 'publicKey' => $subscription['keys']['p256dh'] ?? '',
                'authToken' => $subscription['keys']['auth'] ?? '',
            ]);
            $report = $webPush->sendOneNotification($sub, json_encode($payload, JSON_UNESCAPED_UNICODE));
            if ($report->isSuccess()) return true;
            $status = $report->getResponse()?->getStatusCode();
            $reason = preg_replace('#https?://\S+#i', '[endpoint]', $report->getReason());
            $reason = mb_substr((string)$reason, 0, 240);
            cli::pcl('[PUSH:SEND] falha HTTP ' . ($status ?? '?') . ' reason=' . $reason, 'red');
            return in_array($status, [404, 410], true) ? false : null;
        } catch (\Throwable $e) {
            cli::pcl('[PUSH:SEND] falha: ' . get_class($e), 'red');
        } finally {
            restore_error_handler();
        }
        return null;
    }

    public static function caBundlePath(): ?string
    {
        $locations = openssl_get_cert_locations();
        $candidates = [
            getenv('SPECH_PUSH_CA_BUNDLE') ?: null,
            ini_get('curl.cainfo') ?: null,
            ini_get('openssl.cafile') ?: null,
            $locations['ini_cafile'] ?? null,
            $locations['default_cert_file'] ?? null,
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/etc/pki/ca-trust/extracted/pem/tls-ca-bundle.pem',
            '/etc/ssl/cert.pem',
        ];
        foreach (array_unique(array_filter($candidates, 'is_string')) as $candidate) {
            if ($candidate !== '' && is_file($candidate) && is_readable($candidate)) return $candidate;
        }
        return null;
    }

    private static function loadSubs(): array
    {
        self::initializeStorage();
        $file = self::storageFile();
        $data = self::readJsonFile($file);
        if ($data === null) return [];
        $migrated = self::canonicalize(self::migrateLegacyData($data, AccountIdentity::all()));
        if ($migrated !== $data) self::saveSubs($migrated);
        return $migrated;
    }

    private static function saveSubs(array $data): void
    {
        $file = self::storageFile();
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Não foi possível criar o diretório de subscriptions: {$dir}");
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Não foi possível persistir subscriptions');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('Não foi possível publicar subscriptions persistidas');
        }
        $confirmed = self::readJsonFile($file);
        if ($confirmed !== $data) throw new \RuntimeException('Falha ao confirmar subscriptions persistidas');
    }

    private static function mutateSubs(callable $callback): mixed
    {
        $file = self::storageFile();
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Não foi possível criar o diretório de subscriptions: {$dir}");
        }
        $lock = fopen($file . '.lock', 'c');
        if (!$lock) throw new \RuntimeException('Não foi possível bloquear subscriptions');
        @chmod($file . '.lock', 0600);
        flock($lock, LOCK_EX);
        try {
            $data = self::loadSubs();
            $result = $callback($data);
            self::saveSubs($data);
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Merge legacy stores in priority order. Source files are never removed.
     * @param list<string>|null $legacyFiles
     * @return array{migrated:int,sources:int,path:string}
     */
    public static function migrateStorage(?array $legacyFiles = null): array
    {
        $target = self::storageFile();
        $accounts = AccountIdentity::all();
        $targetRaw = self::readJsonFile($target) ?? [];
        $merged = self::canonicalize(self::migrateLegacyData($targetRaw, $accounts));
        $total = 0;
        $sources = 0;

        foreach ($legacyFiles ?? DataPath::legacyFiles('push_subscriptions.json') as $source) {
            if (self::samePath($source, $target) || !is_file($source)) continue;
            $raw = self::readJsonFile($source);
            if ($raw === null) {
                cli::pcl("[PUSH:MIGRATE] arquivo inválido ignorado source={$source}", 'yellow');
                continue;
            }
            $sources++;
            $before = self::subscriptionCount($merged);
            $incoming = self::canonicalize(self::migrateLegacyData($raw, $accounts));
            $merged = self::mergeStores($merged, $incoming);
            $migrated = self::subscriptionCount($merged) - $before;
            $total += $migrated;
            cli::pcl("[PUSH:MIGRATE] migrated={$migrated} source={$source}", 'green');
        }

        if ($merged !== $targetRaw || ($sources > 0 && !is_file($target))) self::saveSubs($merged);
        self::$initializedFiles[$target] = true;
        return ['migrated' => $total, 'sources' => $sources, 'path' => $target];
    }

    private static function initializeStorage(): void
    {
        $file = self::storageFile();
        if (isset(self::$initializedFiles[$file])) return;
        cli::pcl("[PUSH:STORE] usando path={$file}", 'cyan');
        if (!self::$explicitFile) self::migrateStorage();
        self::$initializedFiles[$file] = true;
    }

    private static function readJsonFile(string $file): ?array
    {
        if (!is_file($file)) return null;
        $contents = @file_get_contents($file);
        if ($contents === false || trim($contents) === '') return null;
        $data = json_decode($contents, true);
        return is_array($data) ? $data : null;
    }

    private static function canonicalize(array $data): array
    {
        $result = [];
        foreach ($data as $accountId => $subscriptions) {
            if ($accountId === '_legacy_unresolved') continue;
            if (!is_array($subscriptions)) continue;
            foreach ($subscriptions as $sub) {
                if (!is_array($sub) || empty($sub['endpoint'])) continue;
                $hash = hash('sha256', (string)$sub['endpoint']);
                $sub['fp'] = (string)$accountId;
                $result[(string)$accountId][$hash] ??= $sub;
            }
        }
        if (!empty($data['_legacy_unresolved']) && is_array($data['_legacy_unresolved'])) {
            $result['_legacy_unresolved'] = $data['_legacy_unresolved'];
        }
        return $result;
    }

    private static function mergeStores(array $preferred, array $fallback): array
    {
        foreach ($fallback as $accountId => $subscriptions) {
            if (!is_array($subscriptions)) continue;
            if ($accountId === '_legacy_unresolved') {
                foreach ($subscriptions as $legacyKey => $legacySubs) {
                    foreach (is_array($legacySubs) ? $legacySubs : [] as $hash => $sub) {
                        $preferred[$accountId][$legacyKey][$hash] ??= $sub;
                    }
                }
                continue;
            }
            foreach ($subscriptions as $hash => $sub) $preferred[$accountId][$hash] ??= $sub;
        }
        return $preferred;
    }

    private static function subscriptionCount(array $data): int
    {
        $count = 0;
        foreach ($data as $accountId => $subscriptions) {
            if ($accountId !== '_legacy_unresolved' && is_array($subscriptions)) $count += count($subscriptions);
        }
        return $count;
    }

    private static function samePath(string $left, string $right): bool
    {
        $leftReal = realpath($left);
        $rightReal = realpath($right);
        return $left === $right || ($leftReal !== false && $rightReal !== false && $leftReal === $rightReal);
    }

    private static function buildAuth(): ?array
    {
        $publicKey = getenv('SPECH_PUSH_PUBLIC_KEY');
        $privateKey = getenv('SPECH_PUSH_PRIVATE_KEY');
        if (!$publicKey || !$privateKey) return null;
        return ['VAPID' => [
            'subject' => getenv('SPECH_PUSH_SUBJECT') ?: 'mailto:suporte@spechshop.com',
            'publicKey' => $publicKey, 'privateKey' => $privateKey,
        ]];
    }
}
