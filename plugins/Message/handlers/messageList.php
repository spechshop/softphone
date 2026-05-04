<?php

namespace handlers;

use plugins\Utils\messages\messageStore;

class messageList
{
    public static function resolve(\Swoole\WebSocket\Server $socket, array $model, int $fd): mixed
    {
        $data = $model['data'] ?? [];
        $limit = $data['limit'] ?? 50;

        $fp = messageStore::getFpFromFd($fd);
        $user = $fp ? messageStore::getSipUserFromFp($fp) : null;
        if (!$user) {
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'] ?? null,
                'type' => 'messageList',
                'data' => ['success' => false, 'error' => 'Not authenticated']
            ]));
        }

        $conversations = messageStore::listConversations($user, (int)$limit);


        return $socket->push($fd, json_encode([
            'byToken' => $model['id'] ?? null,
            'type' => 'messageList',
            'data' => [
                'success' => true,
                'conversations' => $conversations
            ]
        ]));
    }
}
