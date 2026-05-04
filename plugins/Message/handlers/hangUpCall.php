<?php

namespace handlers;

use helpers\utils\CallState;
use libspech\Cache\cache;
use libspech\Network\network;
use libspech\Packet\renderMessages;
use libspech\Sip\sip;

class hangUpCall
{
    public static function resolve(\Swoole\WebSocket\Server $socket, array $model, int $fd): ?bool
    {
        $data = $model['data'];
        $fingerprint = $data['fp'];

        $vault = new \spechphoneVault('/data/spechphone/devices.vault', getenv('SPECH_VAULT_KEY_HEX'));
        if (!$vault->exists($fingerprint)) {
            return $socket->push($fd, json_encode(['byToken' => $model['id'], 'data' => ['success' => false]]));
        }

        if (!cache::get('coroutinesProcess')) cache::set('coroutinesProcess', []);

        // Outgoing call (trunkController) or accepted incoming call (stdClass media state)
        if (array_key_exists($fingerprint, cache::get('coroutinesProcess'))) {
            $phone = cache::get('coroutinesProcess')[$fingerprint];
            $phone->receiveBye = true;
            $phone->callActive = false;
            cache::unset('coroutinesProcess', $fingerprint);

            if (method_exists($phone, 'bye')) {
                // Outgoing call: trunkController handles the SIP BYE
                $phone->bye();
                $phone->close();
                return $socket->push($fd, json_encode(['byToken' => $model['id'], 'data' => ['success' => true]]));
            }
            // Accepted incoming call: signal the media bridge to stop and fall through
            // to send the SIP BYE via the stored invite headers below.
        }

        // Incoming call (ringing or accepted before media was stored)
        $incomingCall = CallState::findIncomingCallForHangup($fingerprint);
        if ($incomingCall) {
            $callId = $incomingCall['call_id'];
            $inviteHeaders = json_decode($incomingCall['invite_headers_json'], true);
            $remoteIp = $incomingCall['remote_ip'];
            $remotePort = $incomingCall['remote_port'];

            if ($incomingCall['status'] === 'ringing') {
                $socket->sendto($remoteIp, $remotePort, renderMessages::respond486Busy($inviteHeaders));
            } else {
                $byePacket = sip::renderSolution([
                    'method' => 'BYE',
                    'methodForParser' => 'BYE sip:' . sip::extractURI($inviteHeaders['From'][0] ?? '')['user'] . '@' . $remoteIp . ' SIP/2.0',
                    'headers' => [
                        'Via' => ['SIP/2.0/UDP ' . network::getLocalIp() . ':4000;branch=z9hG4bK-' . bin2hex(random_bytes(4))],
                        'From' => $inviteHeaders['To'],
                        'To' => $inviteHeaders['From'],
                        'Call-ID' => $inviteHeaders['Call-ID'],
                        'CSeq' => [(((int)$inviteHeaders['CSeq'][0]) + 1) . ' BYE'],
                        'Max-Forwards' => ['70'],
                        'Content-Length' => ['0'],
                    ],
                ]);
                $socket->sendto($remoteIp, $remotePort, $byePacket);
            }

            CallState::$incomingCalls->del($callId);
            return $socket->push($fd, json_encode(['byToken' => $model['id'], 'data' => ['success' => true]]));
        }

        return $socket->push($fd, json_encode(['byToken' => $model['id'], 'data' => ['success' => false]]));
    }
}