<?php

namespace handlers;

use helpers\utils\CallState;
use helpers\utils\OutboundCall;
use libspech\Cache\cache;
use libspech\Cli\cli;
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
        $inboundMediaChannel = null;

        // Outgoing call (trunkController) or accepted incoming call (stdClass media state)
        if (array_key_exists($fingerprint, cache::get('coroutinesProcess'))) {
            $phone = cache::get('coroutinesProcess')[$fingerprint];
            if ($phone instanceof OutboundCall) {
                $phone->hangup();
                return $socket->push($fd, json_encode(['byToken' => $model['id'], 'data' => ['success' => true]]));
            }
            $phone->receiveBye = true;
            $phone->callActive = false;
            cache::unset('coroutinesProcess', $fingerprint);

            if (method_exists($phone, 'bye')) {
                // Outgoing call: trunkController handles the SIP BYE

                $phone->bye();
                $phone->cancel();
                $phone->close();
                return $socket->push($fd, json_encode(['byToken' => $model['id'], 'data' => ['success' => true]]));
            }
            // Accepted incoming call: signal the media bridge to stop and fall through
            // to send the SIP BYE via the stored invite headers below.
            $inboundMediaChannel = $phone->mediaChannel ?? null;
        }

        // Incoming call (ringing or accepted before media was stored)
        $incomingCall = CallState::findIncomingCallForHangup($fingerprint);
        if ($incomingCall) {
            $callId = $incomingCall['call_id'];
            $inviteHeaders = json_decode($incomingCall['invite_headers_json'], true);
            $remoteIp = $incomingCall['remote_ip'];
            $remotePort = $incomingCall['remote_port'];

            if ($incomingCall['status'] === 'ringing') {
                $inviteHeaders['To'][0] = ($inviteHeaders['To'][0] ?? '') . ';tag=' . $incomingCall['to_tag'];
                $socket->sendto($remoteIp, $remotePort, renderMessages::respond486Busy($inviteHeaders));
            } else {
                $byeHeaders = [
                    'Via' => ['SIP/2.0/UDP ' . network::getLocalIp() . ':4000;branch=z9hG4bK-' . bin2hex(random_bytes(4))],
                    'From' => [($inviteHeaders['To'][0] ?? '') . ';tag=' . $incomingCall['to_tag']],
                    'To' => $inviteHeaders['From'],
                    'Call-ID' => $inviteHeaders['Call-ID'],
                    'CSeq' => [(((int)$inviteHeaders['CSeq'][0]) + 1) . ' BYE'],
                    'Max-Forwards' => ['70'],
                    'Contact' => ['<sip:s@' . network::getLocalIp() . ':4000>'],
                    'Content-Length' => ['0'],
                ];
                if (!empty($inviteHeaders['Record-Route'])) {
                    $byeHeaders['Route'] = array_reverse($inviteHeaders['Record-Route']);
                }
                $callerUser = sip::extractURI($inviteHeaders['From'][0] ?? '')['user'] ?? 'unknown';
                $byePacket = sip::renderSolution([
                    'method' => 'BYE',
                    'methodForParser' => "BYE sip:{$callerUser}@{$remoteIp}:{$remotePort} SIP/2.0",
                    'headers' => $byeHeaders,
                ]);
                $socket->sendto($remoteIp, $remotePort, $byePacket);
                cli::pcl("[HANGUP] BYE enviado → {$remoteIp}:{$remotePort} Call-ID:{$callId}", 'yellow');
            }

            CallState::$incomingCalls->del($callId);
            if (is_object($inboundMediaChannel) && method_exists($inboundMediaChannel, 'close')) {
                $inboundMediaChannel->close();
            }
            self::broadcastCallEnded($socket, $fingerprint, $callId);
            return $socket->push($fd, json_encode(['byToken' => $model['id'], 'data' => ['success' => true]]));
        }

        return $socket->push($fd, json_encode(['byToken' => $model['id'], 'data' => ['success' => false]]));
    }

    private static function broadcastCallEnded(object $socket, string $fingerprint, string $callId): void
    {
        $payload = json_encode([
            'type' => 'callEnded',
            'data' => ['callId' => $callId],
        ]);
        foreach (cache::get('connections')[$fingerprint] ?? [] as $clientFd) {
            $socket->push($clientFd, $payload);
        }
    }
}
