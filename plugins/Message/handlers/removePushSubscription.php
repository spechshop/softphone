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

        try {
            $vault = new \spechphoneVault(\helpers\utils\AccountIdentity::vaultPath(), getenv('SPECH_VAULT_KEY_HEX'));
        } catch (\Throwable $e) {
            cli::pcl('[PUSH] Falha ao abrir vault para remover subscription: ' . get_class($e), 'red');
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
            WebPushHelper::removeSubscription($fp, $endpoint);
        } catch (\Throwable $e) {
            cli::pcl('[PUSH] Falha ao persistir remoção de subscription: ' . get_class($e), 'red');
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'] ?? null,
                'data' => ['success' => false, 'error' => 'Falha ao remover subscription'],
            ]));
        }
        cli::pcl("[PUSH] Subscription removida — accountId:{$fp}", 'yellow');

        return $socket->push($fd, json_encode([
            'byToken' => $model['id'] ?? null,
            'data' => ['success' => true],
        ]));
    }
}
