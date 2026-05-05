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
        cli::pcl("[PUSH:SAVE] sipUser={$sipUser} fp={$fp} endpoint=" . substr($sub['endpoint'] ?? '', 0, 60) . '...', 'cyan');

        if (empty($sipUser)) {
            cli::pcl('[PUSH:SAVE] ERRO: sipUser vazio, subscription não salva', 'red');
            return;
        }

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
        cli::pcl("[PUSH:SAVE] OK — {$sipUser} agora tem " . count($data[$sipUser]) . " subscription(s)", 'green');
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
        cli::pcl("[PUSH:NOTIFY] Disparando push para sipUser={$sipUser}", 'cyan');

        $data = self::loadSubs();
        $allKeys = array_keys($data);
        cli::pcl('[PUSH:NOTIFY] sipUsers com subscription: ' . implode(', ', $allKeys ?: ['(nenhum)']), 'cyan');

        $subscriptions = $data[$sipUser] ?? [];
        if (empty($subscriptions)) {
            cli::pcl("[PUSH:NOTIFY] Nenhuma subscription encontrada para {$sipUser} — push não enviado", 'yellow');
            return;
        }

        cli::pcl("[PUSH:NOTIFY] {$sipUser} tem " . count($subscriptions) . " subscription(s)", 'cyan');

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
                $expired[] = $hash;
            }
        }

        if (!empty($expired)) {
            foreach ($expired as $hash) {
                unset($data[$sipUser][$hash]);
            }
            self::saveSubs($data);
            cli::pcl('[PUSH:NOTIFY] ' . count($expired) . " subscription(s) expirada(s) removida(s)", 'yellow');
        }
    }

    public static function send(array $subscription, array $payload): bool
    {
        $auth = self::buildAuth();
        if (!$auth) {
            cli::pcl('[PUSH:SEND] VAPID keys não configuradas — defina SPECH_PUSH_PUBLIC_KEY e SPECH_PUSH_PRIVATE_KEY no .env', 'red');
            return false;
        }

        $endpointShort = substr($subscription['endpoint'] ?? '', 0, 60) . '...';
        cli::pcl("[PUSH:SEND] Enviando para endpoint: {$endpointShort}", 'cyan');

        try {
            $webPush = new WebPush($auth);
            $sub = Subscription::create([
                'endpoint' => $subscription['endpoint'],
                'publicKey' => $subscription['keys']['p256dh'] ?? '',
                'authToken' => $subscription['keys']['auth'] ?? '',
            ]);
            $report = $webPush->sendOneNotification($sub, json_encode($payload, JSON_UNESCAPED_UNICODE));

            if ($report->isSuccess()) {
                cli::pcl('[PUSH:SEND] ✓ Push entregue com sucesso', 'green');
                return true;
            }

            $reason = $report->getReason();
            $statusCode = $report->getResponse()?->getStatusCode() ?? '?';
            cli::pcl("[PUSH:SEND] ✗ Falha — HTTP {$statusCode}: {$reason}", 'red');
            return false;
        } catch (\Throwable $e) {
            cli::pcl('[PUSH:SEND] ✗ Exceção: ' . $e->getMessage(), 'red');
            return false;
        }
    }

    // ── Auth ────────────────────────────────────────────────────────────────

    private static function buildAuth(): ?array
    {
        $publicKey = getenv('SPECH_PUSH_PUBLIC_KEY');
        $privateKey = getenv('SPECH_PUSH_PRIVATE_KEY');

        if (!$publicKey || !$privateKey) {
            return null;
        }

        cli::pcl('[PUSH:AUTH] VAPID public key: ' . substr($publicKey, 0, 20) . '...', 'cyan');
        return [
            'VAPID' => [
                'subject' => getenv('SPECH_PUSH_SUBJECT') ?: 'mailto:suporte@spechshop.com',
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ];
    }
}
