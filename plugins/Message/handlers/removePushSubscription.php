<?php

namespace handlers;

use helpers\utils\WebPushHelper;
use libspech\Cli\cli;

class removePushSubscription
{
    public static function resolve(\Swoole\WebSocket\Server $socket, array $model, int $fd): mixed
    {
        $data = $model['data'] ?? [];
        $fp = $data['fp'] ?? '';
        $endpoint = $data['endpoint'] ?? '';

        if (empty($fp) || empty($endpoint)) {
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

        $sipUser = $vault->get($fp)['sipUser'] ?? '';
        WebPushHelper::removeSubscription($sipUser, $endpoint);

        cli::pcl("[PUSH] Subscription removida — fp:{$fp} sipUser:{$sipUser}", 'yellow');

        return $socket->push($fd, json_encode([
            'byToken' => $model['id'] ?? null,
            'data' => ['success' => true],
        ]));
    }
}
