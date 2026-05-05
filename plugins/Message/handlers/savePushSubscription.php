<?php

namespace handlers;

use helpers\utils\WebPushHelper;
use libspech\Cli\cli;

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

        $vault = new \spechphoneVault('/data/spechphone/devices.vault', getenv('SPECH_VAULT_KEY_HEX'));
        if (!$vault->exists($fp)) {
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'] ?? null,
                'data' => ['success' => false, 'error' => 'Dispositivo não encontrado'],
            ]));
        }

        $userData = $vault->get($fp);
        $sipUser = $userData['sipUser'] ?? '';

        if (empty($sipUser)) {
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'] ?? null,
                'data' => ['success' => false, 'error' => 'Usuário SIP não definido'],
            ]));
        }

        WebPushHelper::saveSubscription($sipUser, $subscription, $fp);

        cli::pcl("[PUSH] Subscription salva — fp:{$fp} sipUser:{$sipUser}", 'green');

        return $socket->push($fd, json_encode([
            'byToken' => $model['id'] ?? null,
            'data' => ['success' => true],
        ]));
    }
}
