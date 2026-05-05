<?php

namespace handlers;

class getPushPublicKey
{
    public static function resolve(\Swoole\WebSocket\Server $socket, array $model, int $fd): mixed
    {
        $publicKey = getenv('SPECH_PUSH_PUBLIC_KEY') ?: null;

        return $socket->push($fd, json_encode([
            'byToken' => $model['id'] ?? null,
            'data' => ['publicKey' => $publicKey],
        ]));
    }
}
