<?php

namespace helpers\utils;

use libspech\Cli\cli;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushHelper
{
    private static string $subFile = '/tmp/data/spechphone/push_subscriptions.json';

    public static function setFile(string $file): void
    {
        self::$subFile = $file;
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
        foreach ($subscriptions as $hash => $sub) if (self::send($sub, $payload) === false) $expired[] = $hash;
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
        if (!file_exists(self::$subFile)) return [];
        $data = json_decode((string)file_get_contents(self::$subFile), true);
        if (!is_array($data)) return [];
        $migrated = self::migrateLegacyData($data, AccountIdentity::all());
        if ($migrated !== $data) self::saveSubs($migrated);
        return $migrated;
    }

    private static function saveSubs(array $data): void
    {
        $dir = dirname(self::$subFile);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $tmp = self::$subFile . '.tmp.' . bin2hex(random_bytes(6));
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        rename($tmp, self::$subFile);
    }

    private static function mutateSubs(callable $callback): mixed
    {
        $dir = dirname(self::$subFile);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $lock = fopen(self::$subFile . '.lock', 'c');
        if (!$lock) throw new \RuntimeException('Não foi possível bloquear subscriptions');
        flock($lock, LOCK_EX);
        $data = self::loadSubs();
        $result = $callback($data);
        self::saveSubs($data);
        flock($lock, LOCK_UN);
        fclose($lock);
        return $result;
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
