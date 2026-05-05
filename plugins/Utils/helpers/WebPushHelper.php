<?php

namespace helpers\utils;

use libspech\Cli\cli;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushHelper
{
    private static string $subFile = '/data/spechphone/push_subscriptions.json';

    // ── Subscription storage ────────────────────────────────────────────────

    public static function saveSubscription(string $sipUser, array $sub, string $fp = ''): void
    {
        $data = self::loadSubs();
        $hash = hash('sha256', $sub['endpoint']);

        if (!isset($data[$sipUser])) {
            $data[$sipUser] = [];
        }

        $existing = $data[$sipUser][$hash] ?? [];
        $data[$sipUser][$hash] = [
            'endpoint' => $sub['endpoint'],
            'keys' => [
                'p256dh' => $sub['keys']['p256dh'] ?? '',
                'auth' => $sub['keys']['auth'] ?? '',
            ],
            'fp' => $fp,
            'created_at' => $existing['created_at'] ?? time(),
            'updated_at' => time(),
        ];

        self::saveSubs($data);
    }

    private static function loadSubs(): array
    {
        if (!file_exists(self::$subFile)) {
            return [];
        }
        $raw = file_get_contents(self::$subFile);
        if (empty($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function saveSubs(array $data): void
    {
        $dir = dirname(self::$subFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $tmp = self::$subFile . '.tmp.' . uniqid();
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        rename($tmp, self::$subFile);
    }

    // ── Send ────────────────────────────────────────────────────────────────

    public static function notifyUser(string $sipUser, array $message): void
    {
        $data = self::loadSubs();
        $subscriptions = $data[$sipUser] ?? [];
        if (empty($subscriptions)) {
            return;
        }

        $from = $message['from'] ?? 'Contato';
        $body = mb_substr($message['body'] ?? '', 0, 120);

        $payload = [
            'type' => 'message',
            'title' => 'Nova mensagem',
            'body' => "{$from}: {$body}",
            'tag' => 'spech-message-' . hash('sha1', $from),
            'url' => '/',
            'from' => $from,
            'messageId' => $message['id'] ?? null,
        ];

        $expired = [];
        foreach ($subscriptions as $hash => $sub) {
            $ok = self::send($sub, $payload);
            if (!$ok) {
                cli::pcl("[PUSH] Subscription inválida ou expirada para {$sipUser} hash:{$hash}", 'yellow');
                $expired[] = $hash;
            } else {
                cli::pcl("[PUSH] Notificação enviada para {$sipUser}", 'green');
            }
        }

        if (!empty($expired)) {
            foreach ($expired as $hash) {
                unset($data[$sipUser][$hash]);
            }
            self::saveSubs($data);
        }
    }

    public static function send(array $subscription, array $payload): bool
    {
        $auth = self::buildAuth();
        if (!$auth) {
            cli::pcl('[PUSH] VAPID keys não configuradas — defina SPECH_PUSH_PUBLIC_KEY e SPECH_PUSH_PRIVATE_KEY no .env', 'red');
            return false;
        }

        try {
            $webPush = new WebPush($auth);
            $sub = Subscription::create([
                'endpoint' => $subscription['endpoint'],
                'publicKey' => $subscription['keys']['p256dh'] ?? '',
                'authToken' => $subscription['keys']['auth'] ?? '',
            ]);
            $report = $webPush->sendOneNotification($sub, json_encode($payload, JSON_UNESCAPED_UNICODE));
            return $report->isSuccess();
        } catch (\Throwable $e) {
            cli::pcl('[PUSH] Erro ao enviar: ' . $e->getMessage(), 'red');
            return false;
        }
    }

    // ── Notify helpers ──────────────────────────────────────────────────────

    private static function buildAuth(): ?array
    {
        $publicKey = getenv('SPECH_PUSH_PUBLIC_KEY');
        $privateKey = getenv('SPECH_PUSH_PRIVATE_KEY');
        if (!$publicKey || !$privateKey) {
            return null;
        }
        return [
            'VAPID' => [
                'subject' => getenv('SPECH_PUSH_SUBJECT') ?: 'mailto:suporte@spechshop.com',
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ];
    }
}
