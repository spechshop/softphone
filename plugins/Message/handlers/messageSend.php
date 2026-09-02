<?php

namespace handlers;

use helpers\utils\PhoneController;
use helpers\utils\SipRegisterManager;
use libspech\Network\network;
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

        $localIp = network::getLocalIp();
        $localPort = 4000;

        $endpoint = SipRegisterManager::parseEndpoint($sipServer);
        $destIp = network::resolveAddress($endpoint['host'], 4);
        $destPort = $endpoint['port'];
        $domain = trim((string)($userData['sipDomain'] ?? '')) ?: $endpoint['host'];
        $callId = bin2hex(random_bytes(16)) . '@' . $localIp;
        $cseq = 1;

        $sipModel = [
            "method" => "MESSAGE",
            "methodForParser" => "MESSAGE sip:{$to}@{$domain} SIP/2.0",
            "headers" => [
                "Via" => [
                    "SIP/2.0/UDP {$localIp}:{$localPort};branch=z9hG4bK-" . bin2hex(random_bytes(12)) . ';rport',
                ],
                "Max-Forwards" => ["70"],
                "To" => ["<sip:{$to}@{$domain}>"],
                "From" => ["<sip:{$sipUser}@{$domain}>;tag=" . bin2hex(random_bytes(8))],
                "Call-ID" => [$callId],
                "CSeq" => ["{$cseq} MESSAGE"],
                "Contact" => ["<sip:{$sipUser}@{$localIp}:{$localPort}>"],
                "User-Agent" => ["SPECHPHONE"],
                "Content-Type" => ["text/plain; charset=UTF-8"],
                "Content-Length" => [(string)strlen($body)]
            ],
            "body" => $body
        ];

        $rawPacket = \libspech\Sip\sip::renderSolution($sipModel);

        // Use the server's bound UDP :4000 transport directly. A loopback UDP
        // client would have an ephemeral physical source port.
        PhoneController::instance($socket)->send($destIp, $destPort, $rawPacket, 'MESSAGE', $callId, $cseq);

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
