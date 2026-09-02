<?php

namespace handlers;

use helpers\utils\WebPushHelper;
use libspech\Cli\cli;
use plugins\Utils\messages\messageStore;

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

        if (messageStore::getFpFromFd($fd) !== $fp) {
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'] ?? null,
                'data' => ['success' => false, 'error' => 'Conta não autenticada'],
            ]));
        }

        $vault = new \spechphoneVault(\helpers\utils\AccountIdentity::vaultPath(), getenv('SPECH_VAULT_KEY_HEX'));
        if (!$vault->exists($fp)) {
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'] ?? null,
                'data' => ['success' => false, 'error' => 'Dispositivo não encontrado'],
            ]));
        }

        WebPushHelper::removeSubscription($fp, $endpoint);
        cli::pcl("[PUSH] Subscription removida — accountId:{$fp}", 'yellow');

        return $socket->push($fd, json_encode([
            'byToken' => $model['id'] ?? null,
            'data' => ['success' => true],
        ]));
    }
}
