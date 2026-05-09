<?php

namespace handlers;

use helpers\utils\CallState;
use helpers\utils\InboundCallSession;
use helpers\utils\SdpHelper;
use libspech\Cli\cli;
use libspech\Network\network;
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
            cli::pcl("[ACCEPT] Chamada não encontrada Call-ID:{$callId}", 'red');
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
            cli::pcl("[ACCEPT] fp:{$fp} não é dono do Call-ID:{$callId} (dono:{$call['fp']})", 'red');
            return $socket->push($fd, json_encode([
                'type' => 'notify',
                'data' => [
                    'type' => 'bg-danger text-white',
                    'message' => 'Chamada não pertence a este dispositivo',
                ],
            ]));
        }
        if ($call['status'] !== 'ringing') {
            cli::pcl("[ACCEPT] Chamada já em status '{$call['status']}', ignorando Call-ID:{$callId}", 'yellow');
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
        cli::pcl("[ACCEPT] Call-ID:{$callId} fp:{$fp}", 'yellow');
        cli::pcl("[ACCEPT] Destino SIP {$call['remote_ip']}:{$call['remote_port']}", 'yellow');
        cli::pcl("[ACCEPT] Via: " . ($inviteHeaders['Via'][0] ?? 'N/A'), 'yellow');
        $sdpParsed = SdpHelper::parseRemoteSdp($inviteSdp ?? []);
        $chosenCodec = SdpHelper::chooseCodec($sdpParsed['codecs']);
        cli::pcl("[ACCEPT] SDP remoto {$sdpParsed['ip']}:{$sdpParsed['port']} codecs:" . implode(',', array_column($sdpParsed['codecs'], 'name')), 'yellow');
        if (!$chosenCodec) {
            cli::pcl("[ACCEPT] Nenhum codec compatível — enviando 606", 'red');
            $socket->sendto($call['remote_ip'], $call['remote_port'], \libspech\Packet\renderMessages::baseResponse($inviteHeaders, "606", "Not Acceptable"));
            CallState::$incomingCalls->del($callId);
            return false;
        }
        cli::pcl("[ACCEPT] Codec: {$chosenCodec['name']}/{$chosenCodec['rate']} pt:{$chosenCodec['pt']}", 'yellow');
        $localRtpPort = network::getFreePort('udp');
        $localIp = network::getLocalIp();
        $localSdp = SdpHelper::buildLocalSdp($localIp, $localRtpPort, $chosenCodec, $sdpParsed['telephone_event']);
        var_dump($localSdp);
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
        if (array_key_exists('Record-Route', $inviteHeaders)) {
            $responseHeaders['Record-Route'] = $inviteHeaders['Record-Route'];
        }
        if (array_key_exists('Route', $inviteHeaders)) {
            $responseHeaders['Route'] = $inviteHeaders['Route'];
        }
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
        cli::pcl("[ACCEPT] 200 OK enviado com sucesso Call-ID:{$callId}", 'green');
        // ── Media bridge ──────────────────────────────────────────────────────
        $userData = $vault->get($fp);
        $userCodec = $userData['codec'] ?? 'PCMA/8000';
        $userFrequency = (int)(explode('/', $userCodec)[1] ?? 8000);
        // State object — flags shared with the media coroutine.
        // Stored in coroutinesProcess so BYE handler and hangUpCall can signal stop.
        // Also exposes send2833() so the DTMF handler can push RFC 2833 packets
        // through the same MediaChannel used by this inbound call.
        $callState = new InboundCallSession();
        $callState->callId = $callId;
        $callState->remoteIp = $sdpParsed['ip'];
        $callState->remotePort = (int)$sdpParsed['port'];
        $callState->ptTelephoneEvent = (int)($sdpParsed['telephone_event']['pt'] ?? 101);
        $callState->telephoneEventClockRate = (int)($sdpParsed['telephone_event']['rate'] ?? 8000);
        \libspech\Cache\cache::subDefine('coroutinesProcess', $fp, $callState);
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
        Coroutine::create(function () use ($socket, $fd, $callId, $fp, $localRtpPort, $localIp, $sdpParsed, $pt, $codecName, $frequency, $channels, $ssrc, $userFrequency, $callState, $userData) {
            $rtpSocket = new \SocketMutable(AF_INET, SOCK_DGRAM, 0);
            $bindOk = $rtpSocket->bind('0.0.0.0', $localRtpPort);
            cli::pcl("[ACCEPT-CO] bind({$localIp}:{$localRtpPort}) => " . ($bindOk ? 'OK' : 'FALHOU'), $bindOk ? 'cyan' : 'red');
            $mediaChannel = new MediaChannel($rtpSocket, $callId);
            $callState->mediaChannel = $mediaChannel;
            $mediaChannel->portList = $localRtpPort;
            $mediaChannel->codecMapper = [$pt => strtoupper("{$codecName}/{$frequency}/{$channels}")];
            $mediaChannel->registerPtCodecs($mediaChannel->codecMapper);
            // Bind eventSock para relay PCM ↔ browser (porta 9600)
            $eventPort = network::getFreePort('udp');
            $mediaChannel->eventSock->bind('0.0.0.0', $eventPort);
            $portHandler = $mediaChannel->eventSock->getsockname()['port'];
            cli::pcl("[ACCEPT-CO] eventSock bound na porta {$portHandler}", 'cyan');
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
            $mediaChannel->enableVAD();


            // Caller → Browser: decodifica RTP do caller → PCM → relay porta 9600
            $mediaChannel->onReceive(function (\libspech\Rtp\rtpc $rtp, array $peer, \libspech\Rtp\MediaChannel $mc, \libspech\Rtp\rtpChannel $rtpChan) use ($callId, $portHandler, $userFrequency, $frequency, $codecName) {
                if (strlen($rtp->payloadRaw) < 1) {
                    return;
                }
                $targetId = "$peer[address]:$peer[port]";
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
                if ($frequency !== $userFrequency) {
                    $pcmData = resampler($pcmData, $frequency, $userFrequency);
                }
                $id = implode(':', array_values($peer));
                $mc->eventSock->sendto('127.0.0.1', 9600, "{$pcmData}__::__{$callId}__::__{$id}__::__{$portHandler}__::__{$userFrequency}__::__{$frequency}");
            });
            $mediaChannel->setVadTimeout(3);
            // Cleanup quando o caller para de enviar RTP
            $mediaChannel->packetOnTimeout(function (string $cid) use ($callState, $fp, $socket) {
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
            });
            $mediaChannel->active = true;
            // Browser → Caller: lê PCM do relay porta 9600 → codifica → envia RTP
            Coroutine::create(function () use (&$mediaChannel, $sdpParsed, $codecName, $frequency, $callState, $userFrequency) {
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
                cli::pcl("[ACCEPT-CO] Browser→Caller coroutine encerrada", 'red');
            });

            $mediaChannel->start();
            $mediaChannel->close();
            cli::pcl("[ACCEPT-CO] mediaChannel->start() chamado — aguardando RTP do caller", 'cyan');
        });
        return true;
    }
}