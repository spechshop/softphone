<?php

namespace handlers;

use plugins\Utils\messages\messageStore;

class messageRead
{
    public static function resolve(\Swoole\WebSocket\Server $socket, array $model, int $fd): mixed
    {
        $data = $model['data'] ?? [];
        $with = $data['with'] ?? '';
        $messageIds = $data['messageIds'] ?? [];

        $fp = messageStore::getFpFromFd($fd);
        if (!$fp) {
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'] ?? null,
                'type' => 'messageRead',
                'data' => ['success' => false, 'error' => 'Not authenticated']
            ]));
        }

        if (empty($with) || !is_array($messageIds)) {
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'] ?? null,
                'type' => 'messageRead',
                'data' => ['success' => false, 'error' => 'Invalid parameters']
            ]));
        }

        if (count($messageIds) > 0) {
            messageStore::markAsRead($fp, $with, $messageIds);
        }

        return $socket->push($fd, json_encode([
            'byToken' => $model['id'] ?? null,
            'type' => 'messageRead',
            'data' => ['success' => true]
        ]));
    }
}
