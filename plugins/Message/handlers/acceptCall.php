<?php

namespace handlers;

use helpers\utils\AudioIpcPacket;
use helpers\utils\CallState;
use helpers\utils\OpusConfig;
use helpers\utils\SdpHelper;
use libspech\Cache\cache;
use libspech\Cli\cli;
use libspech\Network\network;
use libspech\Packet\renderMessages;
use libspech\Rtp\MediaChannel;
use libspech\Sip\sip;
use Swoole\WebSocket\Server;

class callAccept
{
    public static function resolve(Server $socket, array $model, int $fd): ?bool
    {
        $data = $model['data'];
        $fp = $data['fp'] ?? '';
        $callId = $data['callId'] ?? '';
        $vault = new \spechphoneVault(\helpers\utils\AccountIdentity::vaultPath(), getenv('SPECH_VAULT_KEY_HEX'));
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
        $userData = $vault->get($fp);
        $inviteHeaders = json_decode($call['invite_headers_json'], true);
        $inviteSdp = json_decode($call['invite_sdp_json'], true);

        $sdpParsed = SdpHelper::parseRemoteSdp($inviteSdp ?? []);
        $preferredCodec = explode('/', strtoupper((string)($userData['trunkCodec'] ?? '')))[0] ?? '';
        $chosenCodec = SdpHelper::chooseCodec($sdpParsed['codecs'], $preferredCodec);
        if (!$chosenCodec) {
            $socket->sendto($call['remote_ip'], $call['remote_port'], \libspech\Packet\renderMessages::baseResponse($inviteHeaders, "606", "Not Acceptable"));
            CallState::$incomingCalls->del($callId);
            return false;
        }

        $isOpus = strcasecmp((string)$chosenCodec['name'], 'opus') === 0;
        $localOpusConfig = OpusConfig::normalize(
            is_array($userData['opus'] ?? null) ? $userData['opus'] : null
        );
        $sourceSampleRate = max(8000, min(48000, (int)($data['sourceSampleRate']
            ?? (explode('/', (string)($userData['codec'] ?? 'PCMA/8000'))[1] ?? 8000))));
        $sourceChannels = max(1, min(2, (int)($data['sourceChannels'] ?? 1)));
        if ($sourceChannels === 1) {
            // An unverified/mono capture device must never produce a stereo answer.
            $localOpusConfig['channels'] = 1;
            $localOpusConfig['stereo'] = false;
        }
        $effectiveOpusConfig = $isOpus
            ? OpusConfig::negotiate(
                $localOpusConfig,
                (array)($chosenCodec['fmtp_parsed'] ?? []),
                is_int($sdpParsed['ptime']) ? $sdpParsed['ptime'] : null,
            )
            : null;
        if ($isOpus) {
            $chosenCodec['channels'] = $effectiveOpusConfig['channels'];
        }


        $localRtpPort = network::getFreePort('udp');
        $localIp = network::getLocalIp();
        $localSdp = SdpHelper::buildLocalSdp(
            $localIp,
            $localRtpPort,
            $chosenCodec,
            $sdpParsed['telephone_event'],
            $effectiveOpusConfig,
            $isOpus ? (int)$effectiveOpusConfig['ptime'] : null,
            true,
        );


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
            'opus_config' => $effectiveOpusConfig,
            'updated_at' => time(),
        ]));
        $callState = cache::get('coroutinesProcess')[$fp] ?? new \stdClass();
        $callState->receiveBye = false;
        $callState->callActive = true;
        $callState->error = false;
        $callState->callId = $callId;
        \libspech\Cache\cache::subDefine('coroutinesProcess', $fp, $callState);


        foreach (\libspech\Cache\cache::get('connections')[$fp] ?? [] as $clientFd) {
            if ($effectiveOpusConfig !== null) {
                $socket->push($clientFd, json_encode([
                    'type' => 'opusNegotiated',
                    'data' => $effectiveOpusConfig,
                ]));
            }
            $socket->push($clientFd, json_encode([
                'type' => 'event',
                'data' => 'callAccept',
            ]));
            $socket->push($clientFd, json_encode([
                'type' => 'event',
                'data' => 'callActive',
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
        $mapper = [$pt => strtoupper("{$codecName}/{$frequency}/{$channels}")];
        if (is_array($sdpParsed['telephone_event'])) {
            $mapper[(int)$sdpParsed['telephone_event']['pt']] = 'telephone-event/'
                . (int)$sdpParsed['telephone_event']['rate'] . '/1';
        }
        $packetTime = $isOpus ? (int)$effectiveOpusConfig['ptime'] : 20;
        $mediaChannel->setPacketTime($packetTime);
        $mediaChannel->codecMapper = $mapper;
        $mediaChannel->txCodecMapper = $mapper;
        $mediaChannel->rxCodecMapper = $mapper;
        $mediaChannel->registerPtCodecs($mapper);
        if (is_array($sdpParsed['telephone_event']) && (int)$sdpParsed['telephone_event']['pt'] !== 101) {
            unset($mediaChannel->ptCodecs[101], $mediaChannel->ptFrequencies[101]);
        }


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
            'txPt' => $pt,
            'rxPt' => $pt,
            'timestamp' => time(),
            'config' => $isOpus ? OpusConfig::mediaConfig($effectiveOpusConfig) : [],
            'ssrc' => $ssrc,
            'frequency' => $frequency,
            'channels' => $channels,
            'ptime' => $packetTime,
            'leg' => 'a',
            'txCodecMapper' => $mapper,
            'rxCodecMapper' => $mapper,
        ]);
        $remoteMemberId = $sdpParsed['ip'] . ':' . $sdpParsed['port'];
        if ($isOpus && isset($mediaChannel->members[$remoteMemberId]['opusEncoder'])) {
            $mediaChannel->members[$remoteMemberId]['opusEffectiveConfig'] = $effectiveOpusConfig;
            $mediaChannel->members[$remoteMemberId]['opusEncoderApplied'] = OpusConfig::applyEncoder(
                $mediaChannel->members[$remoteMemberId]['opusEncoder'],
                $effectiveOpusConfig,
            );
        }


        // MediaChannel also decodes stateful codecs for relay; the browser bridge
        // uses independent Opus/G.729 state because a decoder cannot advance twice.
        $browserOpusDecoder = $isOpus ? new \opusChannel(OpusConfig::RTP_RATE, $channels) : null;
        $browserG729Decoder = strcasecmp((string)$codecName, 'G729') === 0 ? new \bcg729Channel() : null;
        $mediaChannel->onReceive(function (\libspech\Rtp\rtpc $rtpc, array $peer, \libspech\Rtp\MediaChannel $mc, \libspech\Rtp\rtpChannel $rtpChan) use ($callId, $portHandler, $frequency, $codecName, $browserOpusDecoder, $browserG729Decoder, $channels) {
            if (strlen($rtpc->payloadRaw) < 1) {
                return;
            }
            $targetId = "{$peer['address']}:{$peer['port']}";


            try {
                $pcmData = match (strtoupper($codecName)) {
                    'PCMU' => decodePcmuToPcm($rtpc->payloadRaw),
                    'PCMA' => decodePcmaToPcm($rtpc->payloadRaw),
                    'G729' => $browserG729Decoder?->decode($rtpc->payloadRaw),
                    'GSM' => $mc->members[$targetId]['gsmDecodedPcm'] ?? false,
                    'OPUS' => $browserOpusDecoder?->decode($rtpc->payloadRaw),
                    'L16' => decodeL16ToPcm($rtpc->payloadRaw),
                    'TELEPHONE-EVENT' => false,
                    default => false,
                };
            } catch (\Throwable) {
                return;
            }


            if (!$pcmData) {
                return;
            }

            // maxplaybackrate constrains SDP/codec behavior; it is not an
            // intermediate PCM transport rate.
            $decodedFrequency = strtoupper($codecName) === 'OPUS'
                ? OpusConfig::RTP_RATE
                : (int)$frequency;
            $id = "{$peer['address']}:{$peer['port']}";
            //cli::pcl("[ACCEPT-CO] RTP received from {$id} codec:{$codecName} pt:{$rtp->payloadType} freq:{$frequency} ssrc:{$rtp->ssrc}}", 'cyan');

            $packet = new AudioIpcPacket(
                $pcmData,
                $callId,
                $id,
                $decodedFrequency,
                $channels,
                $portHandler,
            );
            $mc->eventSock->sendto('127.0.0.1', 9966, $packet->encode());
        });

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

        $mediaChannel->onStart(function () use (&$mediaChannel, &$callState, $callId, $sourceSampleRate, $sourceChannels) {
            cli::pcl("INICIANDO LISTENER DO AUDIO DO NAVEGADOR", 'bold_green');
            //$mediaChannel->eventSock->sendto('127.0.0.1', 9966, str_repeat('0', 12));
            while (true) {
                $peer = null;
                $raw = $mediaChannel->eventSock->recvfrom($peer, 1);


                if (!$callState->callActive || $callState->receiveBye) {
                    cli::pcl("[ACCEPT-CO] Recebendo bye", 'red');
                    break;
                }


                if (!$raw) {
                    continue;
                }
                $packet = AudioIpcPacket::decode($raw)
                    ?? AudioIpcPacket::decodeLegacyCapture($raw, $sourceSampleRate, $sourceChannels);
                if (!$packet instanceof AudioIpcPacket || ($packet->stream !== $callId && $packet->stream !== 'legacy')) {
                    continue;
                }
                // Conversion, codec state, accumulation, RTP timestamps and
                // packetization are owned by libspech's negotiated member.
                try {
                    $mediaChannel->sendPcmToLeg(
                        'a',
                        $packet->payload,
                        $packet->sampleRate,
                        $packet->channels,
                    );
                } catch (\Throwable) {
                    continue;
                }
            }


            cli::pcl(date('H:i:s') . " [ACCEPT-CO] Browser→Caller coroutine encerrada", 'red');
        }
        );


        $start = microtime(true);
        $mediaChannel->start();


        $mediaChannel->block(function () use ($start) {
            $msDiff = round(microtime(true) - $start, 3);
            cli::pcl("[ACCEPT-CO] mediaChannel->block() Iniciado após {$msDiff}ms", 'bold_green');
        });
        $mediaChannel->close();
        if ($browserOpusDecoder !== null) $browserOpusDecoder->destroy();
        if ($browserG729Decoder !== null && method_exists($browserG729Decoder, 'close')) {
            $browserG729Decoder->close();
        }


        return true;
    }
}
