<?php

namespace handlers;

use plugins\Utils\messages\messageStore;

class messageHistory
{
    public static function resolve(\Swoole\WebSocket\Server $socket, array $model, int $fd): mixed
    {
        $data = $model['data'] ?? [];
        $with = $data['with'] ?? '';
        $limit = $data['limit'] ?? 50;

        $fp = messageStore::getFpFromFd($fd);
        $user = $fp ? messageStore::getSipUserFromFp($fp) : null;
        if (!$user) {
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'] ?? null,
                'type' => 'messageHistory',
                'data' => ['success' => false, 'error' => 'Not authenticated']
            ]));
        }

        if (empty($with)) {
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'] ?? null,
                'type' => 'messageHistory',
                'data' => ['success' => false, 'error' => 'Invalid parameters']
            ]));
        }

        $history = messageStore::getHistory($user, $with, (int)$limit);

        return $socket->push($fd, json_encode([
            'byToken' => $model['id'] ?? null,
            'type' => 'messageHistory',
            'data' => [
                'success' => true,
                'conversationId' => $history['conversationId'],
                'messages' => $history['messages']
            ]
        ]));
    }
}
