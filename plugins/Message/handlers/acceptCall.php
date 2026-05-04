<?php

namespace handlers;

use helpers\utils\CallState;
use helpers\utils\SdpHelper;
use libspech\Cli\cli;
use libspech\Network\network;
use libspech\Rtp\MediaChannel;
use libspech\Sip\sip;
use Swoole\Coroutine\Socket;
use Swoole\WebSocket\Server;

class callAccept
{
    public static function resolve(Server $socket, array $model, int $fd): ?bool
    {
        $data = $model['data'];
        $fp = $data['fp'] ?? '';
        $callId = $data['callId'] ?? '';

        $vault = new \spechphoneVault('/data/spechphone/devices.vault', getenv('SPECH_VAULT_KEY_HEX'));
        if (!$vault->exists($fp)) {
            return $socket->push($fd, json_encode(['type' => 'notify', 'data' => ['type' => 'bg-danger text-white', 'message' => 'Token inválido']]));
        }

        if (CallState::$incomingCalls === null || !CallState::$incomingCalls->exist($callId)) {
            cli::pcl("[ACCEPT] Chamada não encontrada Call-ID:{$callId}", 'red');
            return $socket->push($fd, json_encode(['type' => 'notify', 'data' => ['type' => 'bg-danger text-white', 'message' => 'Chamada não encontrada']]));
        }

        $call = CallState::$incomingCalls->get($callId);
        if ($call['fp'] !== $fp) {
            cli::pcl("[ACCEPT] fp:{$fp} não é dono do Call-ID:{$callId} (dono:{$call['fp']})", 'red');
            return $socket->push($fd, json_encode(['type' => 'notify', 'data' => ['type' => 'bg-danger text-white', 'message' => 'Chamada não pertence a este dispositivo']]));
        }

        if ($call['status'] !== 'ringing') {
            cli::pcl("[ACCEPT] Chamada já em status '{$call['status']}', ignorando Call-ID:{$callId}", 'yellow');
            return $socket->push($fd, json_encode(['type' => 'notify', 'data' => ['type' => 'bg-warning text-dark', 'message' => 'Chamada não está tocando']]));
        }

        $inviteHeaders = json_decode($call['invite_headers_json'], true);
        $inviteSdp = json_decode($call['invite_sdp_json'], true);

        cli::pcl("[ACCEPT] Call-ID:{$callId} fp:{$fp}", 'yellow');
        cli::pcl("[ACCEPT] Destino SIP {$call['remote_ip']}:{$call['remote_port']}", 'yellow');
        cli::pcl("[ACCEPT] Via: " . ($inviteHeaders['Via'][0] ?? 'N/A'), 'yellow');

        $sdpParsed = SdpHelper::parseRemoteSdp($inviteSdp ?? []);
        $chosenCodec = SdpHelper::chooseCodec($sdpParsed['codecs']);
        cli::pcl("[ACCEPT] SDP remoto {$sdpParsed['ip']}:{$sdpParsed['port']} codecs:" . implode(',', array_column($sdpParsed['codecs'], 'name')), 'yellow');

        if (!$chosenCodec) {
            cli::pcl("[ACCEPT] Nenhum codec compatível — enviando 606", 'red');
            $socket->sendto($call['remote_ip'], $call['remote_port'],
                \libspech\Packet\renderMessages::baseResponse($inviteHeaders, "606", "Not Acceptable"));
            CallState::$incomingCalls->del($callId);
            return false;
        }
        cli::pcl("[ACCEPT] Codec: {$chosenCodec['name']}/{$chosenCodec['rate']} pt:{$chosenCodec['pt']}", 'yellow');

        $localRtpPort = network::getFreePort('udp');
        $localIp = network::getLocalIp();

        $localSdp = SdpHelper::buildLocalSdp($localIp, $localRtpPort, $chosenCodec, $sdpParsed['telephone_event']);
        cli::pcl("[ACCEPT] SDP local — porta RTP:{$localRtpPort} (" . strlen($localSdp) . " bytes)", 'yellow');

        $responseHeaders = [
            'Via' => $inviteHeaders['Via'],
            'From' => $inviteHeaders['From'],
            'To' => [$inviteHeaders['To'][0] . ';tag=' . $call['to_tag']],
            'Call-ID' => $inviteHeaders['Call-ID'],
            'CSeq' => $inviteHeaders['CSeq'],
            'Contact' => ['<sip:s@' . $localIp . ':4000>'],
            'Content-Type' => ['application/sdp'],
            'Content-Length' => [(string)strlen($localSdp)],
            'Allow' => ['INVITE, ACK, BYE, CANCEL, OPTIONS, MESSAGE, INFO, REGISTER'],
            'Server' => ['SPECHSHOP LIB'],
        ];
        if (array_key_exists('Record-Route', $inviteHeaders)) $responseHeaders['Record-Route'] = $inviteHeaders['Record-Route'];
        if (array_key_exists('Route', $inviteHeaders)) $responseHeaders['Route'] = $inviteHeaders['Route'];

        cli::pcl("[ACCEPT] Enviando 200 OK → {$call['remote_ip']}:{$call['remote_port']} To-tag:{$call['to_tag']}", 'green');
        $renderOK = sip::renderSolution([
            'method' => '200',
            'methodForParser' => 'SIP/2.0 200 OK',
            'headers' => $responseHeaders,
            'body' => $localSdp,
        ]);
        cli::pcl($renderOK);
        $socket->sendto($call['remote_ip'], $call['remote_port'], $renderOK);

        CallState::$incomingCalls->set($callId, array_merge($call, [
            'status' => 'accepted',
            'local_rtp_port' => $localRtpPort,
            'remote_rtp_ip' => $sdpParsed['ip'],
            'remote_rtp_port' => $sdpParsed['port'],
            'codec' => $chosenCodec['name'],
            'frequency' => $chosenCodec['rate'],
            'updated_at' => time(),
        ]));

        foreach (\libspech\Cache\cache::get('connections')[$fp] ?? [] as $clientFd) {
            $socket->push($clientFd, json_encode(['type' => 'event', 'data' => 'callAccept']));
            $socket->push($clientFd, json_encode([
                'type' => 'notify',
                'data' => ['type' => 'bg-success text-white', 'message' => 'Chamada aceita'],
            ]));
        }
        cli::pcl("[ACCEPT] 200 OK enviado com sucesso Call-ID:{$callId}", 'green');

        // ── Media bridge ──────────────────────────────────────────────────────
        $userData = $vault->get($fp);
        $userCodec = $userData['codec'] ?? 'PCMA/8000';
        $userFrequency = (int)(explode('/', $userCodec)[1] ?? 8000);

        // State object — flags shared with the media coroutine.
        // Stored in coroutinesProcess so BYE handler and hangUpCall can signal stop.
        $callState = new \stdClass();
        $callState->receiveBye = false;
        $callState->callActive = true;
        $callState->error = false;
        $callState->callId = $callId;
        \libspech\Cache\cache::subDefine('coroutinesProcess', $fp, $callState);

        $pt = $chosenCodec['pt'];
        $codecName = $chosenCodec['name'];
        $frequency = $chosenCodec['rate'];
        $channels = $chosenCodec['channels'] ?? 1;

        // Extract remote SSRC from offer SDP a= lines
        $ssrc = random_int(0, 0xFFFFFFFF);
        foreach ($inviteSdp['a'] ?? [] as $aLine) {
            foreach (explode(' ', $aLine) as $part) {
                $kv = explode(':', $part, 2);
                if ($kv[0] === 'ssrc') {
                    $ssrc = (int)($kv[1] ?? $ssrc);
                    break 2;
                }
            }
        }


        $rtpSocket = new \SocketMutable(AF_INET, SOCK_DGRAM, SOL_UDP);
        $bindOk = $rtpSocket->bind('0.0.0.0', $localRtpPort);
        cli::pcl("[ACCEPT-CO] bind({$localIp}:{$localRtpPort}) => " . ($bindOk ? 'OK' : 'FALHOU'), $bindOk ? 'cyan' : 'red');


        cli::pcl("[ACCEPT-CO] Coroutine iniciada — ligando socket UDP {$localIp}:{$localRtpPort}", 'cyan');

        $mediaChannel = new MediaChannel($rtpSocket, $callId);
        $mediaChannel->portList = $localRtpPort;
        $mediaChannel->codecMapper = [
            $pt => strtoupper("{$codecName}/{$frequency}/{$channels}"),
        ];

        $mediaChannel->registerPtCodecs($mediaChannel->codecMapper);

        // Membro 1: endpoint RTP remoto (caller SIP)
        cli::pcl("[ACCEPT-CO] addMember REMOTO {$sdpParsed['ip']}:{$sdpParsed['port']} codec:{$codecName} pt:{$pt} freq:{$frequency} ssrc:{$ssrc}", 'cyan');
        $mediaChannel->addMember([
            'address' => $sdpParsed['ip'],
            'port' => $sdpParsed['port'],
            'codec' => $codecName,
            'pt' => $pt,
            'timestamp' => time(),
            'config' => [],
            'ssrc' => $ssrc,
            'frequency' => $frequency,
            'channels' => $channels,
        ]);


        $freePort = network::getFreePort();
        $eventSock = new Socket(AF_INET, SOCK_DGRAM, 0);

        $eventSock->bind('0.0.0.0', $freePort);
        $portHandler = $eventSock->getsockname()['port'];


        $mediaChannel->onReceive(function (\libspech\Rtp\rtpc $rtpc, array $peer, \libspech\Rtp\MediaChannel $mediaChannel) use ($userFrequency, $eventSock, $callId, &$portHandler) {

            if (strlen($rtpc->payloadRaw) < 12) return;

            $targetId = $peer['address'] . ':' . $peer['port'];


            $ssrc = $rtpc->ssrc;


            $frequencyPacket = $mediaChannel->getFrequencyFromPtCodec($rtpc->payloadType);


            $packetCodecName = $mediaChannel->resolveCodecNameFromPt($rtpc->payloadType);


            switch (strtoupper($packetCodecName)) {
                case 'PCMU':
                    $pcmData = decodePcmuToPcm($rtpc->payloadRaw);
                    break;
                case 'PCMA':
                    $pcmData = decodePcmaToPcm($rtpc->payloadRaw);
                    break;
                case 'G729':
                    $pcmData = $mediaChannel->members[$targetId]['rtpChannel']->bcg729Channel->decode($rtpc->payloadRaw);
                    break;
                case 'OPUS':
                    $pcmData = $mediaChannel->opusChannel->decode($rtpc->payloadRaw);
                    // cli::pcl("Recebendo " . strlen($pcmData) . " bytes de {$peer['address']}:{$peer['port']} | Sequence: $rtpc->sequence | TimeStamp: {$rtpc->timestamp} | SSRC: {$rtpChannel->ssrc}", 'bold_yellow');

                    $pcmData = resampler($pcmData, 48000, 8000);


                    break;
                case 'L16':
                    $pcmData = decodeL16ToPcm($rtpc->payloadRaw);
                    break;
                default:
                    cli::pcl("Codec não suportado");
                    return;
                    break;
            };
            $id = implode(':', array_values($peer));


            // aqui vem rtp de quem ta me ligando
            //cli::pcl("received ".strlen($pcmData)." bytes from {$peer['address']}:{$peer['port']}", 'bold_yellow');
            $eventSock->sendto('127.0.0.1', 9600, "{$pcmData}__::__{$callId}__::__{$id}__::__{$portHandler}__::__{$userFrequency}__::__{$frequencyPacket}");
        });


        for (; ;) {
            $first = $rtpSocket->recvfrom($peer, 10);
            if ($first) {
                cli::pcl("received " . strlen($first) . " bytes from {$peer['address']}:{$peer['port']}", 'bold_yellow');
                break;
            }
        }
        $mediaChannel->start();
        cli::pcl("[ACCEPT-CO] mediaChannel->start() retornou — callActive:{$callState->callActive} receiveBye:{$callState->receiveBye}", 'red');
        cli::pcl("[ACCEPT-CO] unblock() chamado, coroutine encerrando", 'red');



        return true;
    }
}
