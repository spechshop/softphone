<?php

namespace handlers;

use helpers\utils\CallState;
use helpers\utils\SdpHelper;
use libspech\Cache\cache;
use libspech\Cli\cli;
use libspech\Network\network;
use libspech\Packet\renderMessages;
use libspech\Rtp\MediaChannel;
use libspech\Sip\sip;
use Swoole\Coroutine;
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
            return $socket->push($fd, json_encode([
                'type' => 'notify',
                'data' => [
                    'type' => 'bg-danger text-white',
                    'message' => 'Token inválido',
                ],
            ]));
        }
        if (CallState::$incomingCalls === null || !CallState::$incomingCalls->exist($callId)) {
            return $socket->push($fd, json_encode([
                'type' => 'notify',
                'data' => [
                    'type' => 'bg-danger text-white',
                    'message' => 'Chamada não encontrada',
                ],
            ]));
        }
        $call = CallState::$incomingCalls->get($callId);
        if ($call['fp'] !== $fp) {
            return $socket->push($fd, json_encode([
                'type' => 'notify',
                'data' => [
                    'type' => 'bg-danger text-white',
                    'message' => 'Chamada não pertence a este dispositivo',
                ],
            ]));
        }
        if ($call['status'] !== 'ringing') {
            return $socket->push($fd, json_encode([
                'type' => 'notify',
                'data' => [
                    'type' => 'bg-warning text-dark',
                    'message' => 'Chamada não está tocando',
                ],
            ]));
        }
        $inviteHeaders = json_decode($call['invite_headers_json'], true);
        $inviteSdp = json_decode($call['invite_sdp_json'], true);

        $sdpParsed = SdpHelper::parseRemoteSdp($inviteSdp ?? []);
        $chosenCodec = SdpHelper::chooseCodec($sdpParsed['codecs']);
        if (!$chosenCodec) {
            $socket->sendto($call['remote_ip'], $call['remote_port'], \libspech\Packet\renderMessages::baseResponse($inviteHeaders, "606", "Not Acceptable"));
            CallState::$incomingCalls->del($callId);
            return false;
        }


        $localRtpPort = network::getFreePort('udp');
        $localIp = network::getLocalIp();
        $localSdp = SdpHelper::buildLocalSdp($localIp, $localRtpPort, $chosenCodec, $sdpParsed['telephone_event']);


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
        if (array_key_exists('Record-Route', $inviteHeaders)) {
            $responseHeaders['Record-Route'] = $inviteHeaders['Record-Route'];
        }
        if (array_key_exists('Route', $inviteHeaders)) {
            $responseHeaders['Route'] = $inviteHeaders['Route'];
        }
        $renderOK = sip::renderSolution([
            'method' => '200',
            'methodForParser' => 'SIP/2.0 200 OK',
            'headers' => $responseHeaders,
            'body' => $localSdp,
        ]);

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
        $callState = cache::get('coroutinesProcess')[$fp] ?? new \stdClass();
        $callState->receiveBye = false;
        $callState->callActive = true;
        $callState->error = false;
        $callState->callId = $callId;
        \libspech\Cache\cache::subDefine('coroutinesProcess', $fp, $callState);


        foreach (\libspech\Cache\cache::get('connections')[$fp] ?? [] as $clientFd) {
            $socket->push($clientFd, json_encode([
                'type' => 'event',
                'data' => 'callAccept',
            ]));
            $socket->push($clientFd, json_encode([
                'type' => 'notify',
                'data' => [
                    'type' => 'bg-success text-white',
                    'message' => 'Chamada aceita',
                ],
            ]));
        }
        // ── Media bridge ──────────────────────────────────────────────────────
        $userData = $vault->get($fp);
        $userCodec = $userData['codec'] ?? 'PCMA/8000';
        $userFrequency = (int)(explode('/', $userCodec)[1] ?? 8000);
        // State object — flags shared with the media coroutine.
        // Stored in coroutinesProcess so BYE handler and hangUpCall can signal stop.


        $pt = $chosenCodec['pt'];
        $codecName = $chosenCodec['name'];
        $frequency = $chosenCodec['rate'];
        $channels = $chosenCodec['channels'] ?? 1;
        // Extract remote SSRC from offer SDP a= lines
        $ssrc = random_int(0, 0xffffffff);
        foreach ($inviteSdp['a'] ?? [] as $aLine) {
            foreach (explode(' ', $aLine) as $part) {
                $kv = explode(':', $part, 2);
                if ($kv[0] === 'ssrc') {
                    $ssrc = (int)($kv[1] ?? $ssrc);
                    break 2;
                }
            }
        }


        $rtpSocket = new \SocketMutable(AF_INET, SOCK_DGRAM, 0);
        $bindOk = $rtpSocket->bind('0.0.0.0', $localRtpPort);
        cli::pcl("[ACCEPT-CO] bind({$localIp}:{$localRtpPort}) => " . ($bindOk ? 'OK' : 'FALHOU'), $bindOk ? 'bold_green' : 'bold_red');


        $mediaChannel = new MediaChannel($rtpSocket, $callId);
        $mediaChannel->portList = $localRtpPort;
        $mediaChannel->codecMapper = [$pt => strtoupper("{$codecName}/{$frequency}/{$channels}")];
        $mediaChannel->registerPtCodecs($mediaChannel->codecMapper);


        $eventPort = network::getFreePort('udp');
        $mediaChannel->eventSock->bind('0.0.0.0', $eventPort);
        $portHandler = $mediaChannel->eventSock->getsockname()['port'];


        //cli::pcl("[ACCEPT-CO] eventSock bound na porta {$portHandler}", 'cyan');
        //cli::pcl("[ACCEPT-CO] addMember REMOTO {$sdpParsed['ip']}:{$sdpParsed['port']} codec:{$codecName} pt:{$pt} freq:{$frequency} ssrc:{$ssrc}", 'cyan');
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
        $mediaChannel->enableVAD();


        $mediaChannel->onReceive(function (\libspech\Rtp\rtpc $rtp, array $peer, \libspech\Rtp\MediaChannel $mc, \libspech\Rtp\rtpChannel $rtpChan)
        use ($callId, $portHandler, $userFrequency, $frequency, $codecName) {
            if (strlen($rtp->payloadRaw) < 1) {
                return;
            }


            if (strtoupper($codecName) === 'OPUS') {
                return;
            }
            $pcmData = match (strtoupper($codecName)) {
                'PCMU' => decodePcmuToPcm($rtp->payloadRaw),
                'PCMA' => decodePcmaToPcm($rtp->payloadRaw),
                'G729' => $rtpChan->bcg729Channel->decode($rtp->payloadRaw),
                'L16' => decodeL16ToPcm($rtp->payloadRaw),
                default => false,
            };
            if (!$pcmData) {
                return;
            }


            // NÃO resample aqui — audio.php faz a única conversão para userFrequency.
            // Se resamplear duas vezes o áudio fica em "câmera lenta" (drift acumulado).
            $id = implode(':', array_values($peer));
            //cli::pcl("[ACCEPT-CO] RTP received from {$id} codec:{$codecName} pt:{$rtp->payloadType} freq:{$frequency} ssrc:{$rtp->ssrc}}", 'cyan');

            $mc->eventSock->sendto('127.0.0.1', 9600, "{$pcmData}__::__{$callId}__::__{$id}__::__{$portHandler}__::__{$userFrequency}__::__{$frequency}");
        });
        $mediaChannel->setVadTimeout(3);
        $mediaChannel->onDtmf(function (string $digit) use ($callState, $fp, $socket, &$mediaChannel) {
            cli::pcl("[ACCEPT-CO] DTMF: {$digit}", 'cyan');
            $mediaChannel->send2833($digit);
        });
        $mediaChannel->packetOnTimeout(function (string $cid) use ($sdpParsed, $responseHeaders, $callState, $fp, $socket) {
            $callState->callActive = false;
            \libspech\Cache\cache::unset('coroutinesProcess', $fp);
            \helpers\utils\CallState::$incomingCalls->del($cid);
            foreach (\libspech\Cache\cache::get('connections')[$fp] ?? [] as $clientFd) {
                $socket->push($clientFd, json_encode([
                    'type' => 'event',
                    'data' => 'bye',
                ]));
                $socket->push($clientFd, json_encode([
                    'type' => 'notify',
                    'data' => [
                        'type' => 'bg-warning text-white',
                        'message' => 'Chamada encerrada por inatividade RTP',
                    ],
                ]));
            }
            cli::pcl("[ACCEPT-CO] RTP timeout — chamada encerrada Call-ID:{$cid}", 'red');
            $modelBye = renderMessages::generateBye($responseHeaders);
            $renderBye = sip::renderSolution($modelBye);
            $socket->sendto($sdpParsed['ip'], $sdpParsed['port'], $renderBye);
            cli::pcl("[ACCEPT-CO] Bye enviado para {$sdpParsed['ip']}:{$sdpParsed['port']}", 'cyan');


            // enviar bye

        });
        $mediaChannel->active = true;
        $callState = cache::get('coroutinesProcess')[$fp] ?? new \stdClass();
        $callState->receiveBye = false;
        $callState->callActive = true;
        $callState->error = false;
        $callState->callId = $callId;
        $callState->mediaChannel = $mediaChannel;
        \libspech\Cache\cache::subDefine('coroutinesProcess', $fp, $callState);

        $mediaChannel->onStart(function () use (&$mediaChannel, $sdpParsed, $codecName, $frequency, &$callState, $userFrequency) {
            cli::pcl("[ACCEPT-CO] Browser→Caller coroutine iniciada", 'cyan');
            //$mediaChannel->eventSock->sendto('127.0.0.1', 9600, str_repeat('0', 12));
            $pcmBuffer = '';
            $SRC_RATE = $userFrequency;
            $PCM_FRAME_BYTES = (int)($SRC_RATE * 0.02) * 2;
            while (true) {
                $peer = null;
                $raw = $mediaChannel->eventSock->recvfrom($peer, 1);


                if (!$callState->callActive || $callState->receiveBye) {
                    cli::pcl("[ACCEPT-CO] Recebendo bye", 'red');
                    break;
                }


                if (!$raw || strlen($raw) < 12) {
                    cli::pcl("[ACCEPT-CO] Raw data is invalid or too short", 'red');

                    var_dump(strlen($raw));
                    Coroutine::sleep(1);
                    continue;
                }
                $pcmBuffer .= explode('__::__', $raw, 2)[0];
                while (strlen($pcmBuffer) >= $PCM_FRAME_BYTES) {
                    $pcmChunk = substr($pcmBuffer, 0, $PCM_FRAME_BYTES);
                    $pcmBuffer = substr($pcmBuffer, $PCM_FRAME_BYTES);
                    if ($SRC_RATE !== $frequency) {
                        $pcmChunk = resampler($pcmChunk, $SRC_RATE, $frequency);
                    }
                    $encode = match (strtoupper($codecName)) {
                        'PCMU' => encodePcmToPcmu($pcmChunk),
                        'PCMA' => encodePcmToPcma($pcmChunk),
                        'G729' => $mediaChannel->channelEncode->encode($pcmChunk),
                        'L16' => encodePcmToL16($pcmChunk),
                        default => false,
                    };
                    if (!$encode) {
                        continue;
                    }
                    $member = $mediaChannel->members["{$sdpParsed['ip']}:{$sdpParsed['port']}"] ?? null;
                    if (!$member) {
                        continue;
                    }
                    $mediaChannel->socket->sendto($sdpParsed['ip'], $sdpParsed['port'], $member['rtpChannel']->buildAudioPacket($encode));
                }
            }


            cli::pcl(date('H:i:s') . " [ACCEPT-CO] Browser→Caller coroutine encerrada", 'red');
        }
        );

        $mediaChannel->start();
        cli::pcl("[ACCEPT-CO] mediaChannel->start() chamado — aguardando RTP do caller", 'cyan');

        return true;
    }
}