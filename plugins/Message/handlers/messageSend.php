<?php

namespace handlers;

use plugins\Utils\messages\messageStore;

class messageSend
{
    public static function resolve(\Swoole\WebSocket\Server $socket, array $model, int $fd): mixed
    {
        $data = $model['data'] ?? [];
        $to = $data['to'] ?? '';
        $body = $data['body'] ?? '';

        $from = messageStore::getFpFromFd($fd);
        if (!$from) {
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'] ?? null,
                'type' => 'messageSend',
                'data' => ['success' => false, 'error' => 'Not authenticated']
            ]));
        }

        if (empty($to) || empty($body)) {
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'] ?? null,
                'type' => 'messageSend',
                'data' => ['success' => false, 'error' => 'Invalid parameters']
            ]));
        }

        $vault = new \spechphoneVault('/data/spechphone/devices.vault', getenv('SPECH_VAULT_KEY_HEX'));
        if (!$vault->exists($from)) {
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'] ?? null,
                'type' => 'messageSend',
                'data' => ['success' => false, 'error' => 'Vault not found']
            ]));
        }

        $userData = $vault->get($from);
        $sipServer = $userData['sipServer'] ?? '';
        $sipUser = $userData['sipUser'] ?? '';

        if (empty($sipServer) || empty($sipUser)) {
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'] ?? null,
                'type' => 'messageSend',
                'data' => ['success' => false, 'error' => 'SIP credentials missing']
            ]));
        }

        $localIp = \libspech\Network\network::getLocalIp();
        $localPort = 4000;

        $destIp = filter_var($sipServer, FILTER_VALIDATE_IP) ? $sipServer : gethostbyname($sipServer);
        $destPort = 5060;

        $sipModel = [
            "method" => "MESSAGE",
            "methodForParser" => "MESSAGE sip:{$to}@{$sipServer} SIP/2.0",
            "headers" => [
                "Via" => [
                    "SIP/2.0/UDP {$destIp}:{$destPort};branch=z9hG4bK" . uniqid(),
                    "SIP/2.0/UDP {$localIp}:{$localPort};branch=z9hG4bK" . uniqid(),

                ],
                "Max-Forwards" => ["70"],
                "To" => ["<sip:{$to}@{$sipServer}>"],
                "From" => ["<sip:{$sipUser}@{$sipServer}>;tag=" . uniqid(time())],
                "Call-ID" => [md5(uniqid()) . "@" . $localIp],
                "CSeq" => ["1 MESSAGE"],
                "User-Agent" => ["SPECHPHONE"],
                "Content-Type" => ["text/plain; charset=UTF-8"],
                "Content-Length" => [(string)strlen($body)]
            ],
            "body" => $body
        ];

        $rawPacket = \libspech\Sip\sip::renderSolution($sipModel);

        // Send to local server so it routes to the destination
        $client = new \Swoole\Coroutine\Client(SWOOLE_SOCK_UDP);
        $client->connect('127.0.0.1', 4000);
        $client->send($rawPacket);
        $client->close();

        $msg = messageStore::saveMessage($sipUser, $to, $body);

        if ($msg) {
            messageStore::sendRealtime($socket, $to, [
                'type' => 'messageNew',
                'data' => ['message' => $msg]
            ]);
            go(fn() => \helpers\utils\WebPushHelper::notifyUser($to, $msg));
        }

        return $socket->push($fd, json_encode([
            'byToken' => $model['id'] ?? null,
            'type' => 'messageSend',
            'data' => ['success' => true, 'message' => $msg]
        ]));
    }
}
