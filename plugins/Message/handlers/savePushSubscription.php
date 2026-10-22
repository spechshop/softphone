<?php

namespace handlers;

use helpers\utils\WebPushHelper;
use libspech\Cli\cli;
use plugins\Utils\messages\messageStore;

class savePushSubscription
{
    public static function resolve(\Swoole\WebSocket\Server $socket, array $model, int $fd): mixed
    {
        $data = $model['data'] ?? [];
        $fp = $data['fp'] ?? '';
        $subscription = $data['subscription'] ?? [];

        $endpoint = $subscription['endpoint'] ?? '';
        $p256dh = $subscription['keys']['p256dh'] ?? '';
        $auth = $subscription['keys']['auth'] ?? '';

        if (empty($fp) || empty($endpoint) || empty($p256dh) || empty($auth)) {
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'] ?? null,
                'data' => ['success' => false, 'error' => 'Parâmetros inválidos'],
            ]));
        }

        if (messageStore::getFpFromFd($fd) !== $fp) {
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'] ?? null,
                'data' => ['success' => false, 'error' => 'Conta não autenticada'],
            ]));
        }

        try {
            $vault = new \spechphoneVault(\helpers\utils\AccountIdentity::vaultPath(), getenv('SPECH_VAULT_KEY_HEX'));
        } catch (\Throwable $e) {
            cli::pcl('[PUSH] Falha ao abrir vault para salvar subscription: ' . get_class($e), 'red');
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'] ?? null,
                'data' => ['success' => false, 'error' => 'Não foi possível validar o dispositivo'],
            ]));
        }
        if (!$vault->exists($fp)) {
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'] ?? null,
                'data' => ['success' => false, 'error' => 'Dispositivo não encontrado'],
            ]));
        }

        try {
            if (!WebPushHelper::saveSubscription($fp, $subscription)) {
                return $socket->push($fd, json_encode([
                    'byToken' => $model['id'] ?? null,
                    'data' => ['success' => false, 'error' => 'Subscription inválida'],
                ]));
            }
        } catch (\Throwable $e) {
            cli::pcl('[PUSH] Falha ao persistir subscription: ' . get_class($e), 'red');
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'] ?? null,
                'data' => ['success' => false, 'error' => 'Falha ao salvar subscription'],
            ]));
        }
        cli::pcl("[PUSH] Subscription salva — accountId:{$fp}", 'green');

        return $socket->push($fd, json_encode([
            'byToken' => $model['id'] ?? null,
            'data' => ['success' => true],
        ]));
    }
}
