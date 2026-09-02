<?php

namespace handlers;

use helpers\utils\PhoneController;
use helpers\utils\SipRegisterManager;
use helpers\utils\AccountIdentity;
use libspech\Network\network;
use plugins\Utils\messages\messageStore;

class messageSend
{
    public static function resolve(\Swoole\WebSocket\Server $socket, array $model, int $fd): mixed
    {
        $data = $model['data'] ?? [];
        $to = $data['to'] ?? '';
        $body = $data['body'] ?? '';

        $accountId = messageStore::getFpFromFd($fd);
        if (!$accountId) {
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
        if (!$vault->exists($accountId)) {
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'] ?? null,
                'type' => 'messageSend',
                'data' => ['success' => false, 'error' => 'Vault not found']
            ]));
        }

        $userData = $vault->get($accountId);
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
        $remote = AccountIdentity::parseSipIdentity((string)$to, $domain);
        if ($remote['user'] === '' || $remote['domain'] === '') {
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'] ?? null, 'type' => 'messageSend',
                'data' => ['success' => false, 'error' => 'Invalid SIP destination']
            ]));
        }
        $localUri = AccountIdentity::sipUri($sipUser, $domain);
        $remoteUri = $remote['uri'];
        $callId = bin2hex(random_bytes(16)) . '@' . $localIp;
        $cseq = 1;

        $sipModel = self::buildSipModel($localIp, $localPort, $sipUser, $domain, $remoteUri, $body, $callId, $cseq);

        $rawPacket = \libspech\Sip\sip::renderSolution($sipModel);

        // Use the server's bound UDP :4000 transport directly. A loopback UDP
        // client would have an ephemeral physical source port.
        PhoneController::instance($socket)->send($destIp, $destPort, $rawPacket, 'MESSAGE', $callId, $cseq);

        $msg = messageStore::saveMessage($accountId, $localUri, $remoteUri, $body, 'outbound');

        if ($msg) {
            messageStore::sendRealtime($socket, $accountId, [
                'type' => 'messageNew',
                'data' => ['message' => $msg]
            ]);
        }

        return $socket->push($fd, json_encode([
            'byToken' => $model['id'] ?? null,
            'type' => 'messageSend',
            'data' => ['success' => true, 'message' => $msg]
        ]));
    }

    public static function buildSipModel(
        string $localIp,
        int $localPort,
        string $sipUser,
        string $localDomain,
        string $remoteIdentity,
        string $body,
        string $callId,
        int $cseq = 1
    ): array {
        $localUri = AccountIdentity::sipUri($sipUser, $localDomain);
        $remote = AccountIdentity::parseSipIdentity($remoteIdentity, $localDomain);
        if ($remote['user'] === '' || $remote['domain'] === '') throw new \InvalidArgumentException('Invalid SIP destination');
        $remoteUri = $remote['uri'];
        return [
            'method' => 'MESSAGE', 'methodForParser' => "MESSAGE {$remoteUri} SIP/2.0",
            'headers' => [
                'Via' => ["SIP/2.0/UDP {$localIp}:{$localPort};branch=z9hG4bK-" . bin2hex(random_bytes(12)) . ';rport'],
                'Max-Forwards' => ['70'], 'To' => ["<{$remoteUri}>"],
                'From' => ["<{$localUri}>;tag=" . bin2hex(random_bytes(8))],
                'Call-ID' => [$callId], 'CSeq' => ["{$cseq} MESSAGE"],
                'Contact' => ["<sip:{$sipUser}@{$localIp}:{$localPort}>"], 'User-Agent' => ['SPECHPHONE'],
                'Content-Type' => ['text/plain; charset=UTF-8'], 'Content-Length' => [(string)strlen($body)],
            ],
            'body' => $body,
        ];
    }
}
