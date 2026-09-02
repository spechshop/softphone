<?php

namespace handlers;

use libspech\Cache\cache;

class checkCall
{
    public static function resolve(\Swoole\WebSocket\Server $socket, array $model, int $fd): ?bool
    {
        $data = $model['data'];

        $vault = new \spechphoneVault(\helpers\utils\AccountIdentity::vaultPath(), getenv('SPECH_VAULT_KEY_HEX'));


        $fingerprint = $data['fp'] ?? '';
        if (!$vault->exists($fingerprint)) {
            $vault = new \spechphoneVault(\helpers\utils\AccountIdentity::vaultPath(), getenv('SPECH_VAULT_KEY_HEX'));
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
            $userDatas = $vault->get($data['fp']);
            $lastPacket = $userDatas['lastPacket'] ?? [];
            $renderURI = $lastPacket['headers']['From'][0] ?? 'invalid';
            $socket->push($fd, json_encode([
                'type' => 'brand',
                'data' => $renderURI
            ]));
            $socket->push($fd, json_encode([
                'type' => 'changeCallId',
                'data' => $lastPacket['headers']['Call-ID'][0] ?? ''
            ]));
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'],
                'data' => [
                    'success' => false,
                ]
            ]));
        } else {
            $socket->push($fd, json_encode([
                'type' => 'changeCallId',
                'data' => cache::get('coroutinesProcess')[$fingerprint]->callId
            ]));
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'],
                'data' => [
                    'success' => true,
                ]
            ]));
        }
    }
}
