<?php

namespace handlers;

use helpers\utils\CallState;
use libspech\Cache\cache;
use libspech\Cli\cli;
use libspech\Packet\renderMessages;

class callReject
{
    public static function resolve(\Swoole\WebSocket\Server $socket, array $model, int $fd): ?bool
    {
        $data = $model['data'];
        $fp = $data['fp'] ?? '';
        $callId = $data['callId'] ?? '';

        $vault = new \spechphoneVault('/data/spechphone/devices.vault', getenv('SPECH_VAULT_KEY_HEX'));
        if (!$vault->exists($fp)) {
            return $socket->push($fd, json_encode(['byToken' => $model['id'] ?? null, 'data' => ['success' => false]]));
        }

        if (CallState::$incomingCalls === null || !CallState::$incomingCalls->exist($callId)) {
            return $socket->push($fd, json_encode(['byToken' => $model['id'] ?? null, 'data' => ['success' => false]]));
        }

        $call = CallState::$incomingCalls->get($callId);
        if ($call['fp'] !== $fp) {
            return $socket->push($fd, json_encode(['byToken' => $model['id'] ?? null, 'data' => ['success' => false]]));
        }

        $inviteHeaders = json_decode($call['invite_headers_json'], true);
        $inviteHeaders['To'][0] = ($inviteHeaders['To'][0] ?? '') . ';tag=' . $call['to_tag'];

        $socket->sendto($call['remote_ip'], $call['remote_port'], renderMessages::respond486Busy($inviteHeaders));
        CallState::$incomingCalls->del($callId);

        foreach (cache::get('connections')[$fp] ?? [] as $clientFd) {
            $socket->push($clientFd, json_encode(['type' => 'event', 'data' => 'bye']));
            $socket->push($clientFd, json_encode(['type' => 'notify', 'data' => ['type' => 'bg-secondary text-white', 'message' => 'Chamada rejeitada']]));
        }

        cli::pcl("[INBOUND] callReject fp:{$fp} Call-ID:{$callId}", 'yellow');
        return $socket->push($fd, json_encode(['byToken' => $model['id'] ?? null, 'data' => ['success' => true]]));
    }
}
