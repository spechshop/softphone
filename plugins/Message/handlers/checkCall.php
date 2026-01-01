<?php

namespace handlers;

use cache;

class checkCall
{
    public static function resolve(\Swoole\WebSocket\Server $socket, array $model, int $fd): ?bool
    {
        $data = $model['data'];

        $vault = new \spechphoneVault('/data/spechphone/devices.vault', getenv('SPECH_VAULT_KEY_HEX'));


        $fingerprint = $data['fp'];
        if (!$vault->exists($fingerprint)) {
            print 'fingerprint not exists' . PHP_EOL;
            $vault = new \spechphoneVault('/data/spechphone/devices.vault', getenv('SPECH_VAULT_KEY_HEX'));
            if (!$vault->exists($data['fp'])) {

                $vault->set($data['fp'], $data);
                return true;
            }


            $socket->push($fd, json_encode([
                'type' => 'notify',
                'data' => [
                    'type' => 'bg-danger text-white',
                    'message' => 'Token inválido'
                ]
            ]));
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'],
                'data' => [
                    'success' => false,
                ]
            ]));
        }
        if (!cache::get('coroutinesProcess')) {
            cache::set('coroutinesProcess', []);
        }
        if (!array_key_exists($fingerprint, cache::get('coroutinesProcess'))) {
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'],
                'data' => [
                    'success' => false,
                ]
            ]));
        } else {
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'],
                'data' => [
                    'success' => true,
                ]
            ]));
        }
    }
}