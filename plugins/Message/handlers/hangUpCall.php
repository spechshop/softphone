<?php

namespace handlers;

use cache;
use libspech\Sip\trunkController;

class hangUpCall
{
    public static function resolve(\Swoole\WebSocket\Server $socket, array $model, int $fd): ?bool
    {
        $data = $model['data'];

        $vault = new \spechphoneVault('/data/spechphone/devices.vault', getenv('SPECH_VAULT_KEY_HEX'));


        $fingerprint = $data['fp'];
        if (!$vault->exists($fingerprint)) {
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
        }
        /** @var trunkController $phone */
        $phone = cache::get('coroutinesProcess')[$fingerprint];
        $phone->receiveBye = true;
        $phone->callActive = false;
        $phone->socket->close();
        $phone->bye();
        $phone->close();
        unset($phone);
        cache::unset('coroutinesProcess', $fingerprint);

        return $socket->push($fd, json_encode([
            'byToken' => $model['id'],
            'data' => [
                'success' => true,
            ]
        ]));
    }
}