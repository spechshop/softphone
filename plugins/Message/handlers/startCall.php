<?php

namespace handlers;


use libspech\Cache\cache;
use libspech\Cli\cli;
use libspech\Network\network;
use libspech\Sip\trunkController;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;
use Swoole\Timer;
use Swoole\WebSocket\Server;

class startCall
{

    private static array $connectionTimers = [];


    /**
     * Resolves a specified model by processing its data and interacting with a vault system.
     * Sends notifications to the client via the provided server socket.
     *
     * @param Server $socket The server socket instance used for communication.
     * /**
     * @param array{
     *     id: string,
     *     type: string,
     *     data: array{
     *         sipServer: string,
     *         sipUser: string,
     *         sipDomain: string,
     *         sipPass: string,
     *         transport: string,
     *         stunOn: bool,
     *         stunServer: string,
     *         fp: string
     *     }
     * } $model
     * @param int $fd The file descriptor representing the client connection.
     * @return bool|null Returns true if the operation is successful, or false if data processing fails. Null indicates no value.
     */
    public static function resolve(Server $socket, array $model, int $fd): ?bool
    {


        $data = $model['data'];
        $vault = new \spechphoneVault('/data/spechphone/devices.vault', getenv('SPECH_VAULT_KEY_HEX'));
        if (empty($data['fp'])) {
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'],
                'data' => [
                    'success' => false,
                ]
            ]));
        }
        $fingerprint = $data['fp'];
        if (!$vault->exists($fingerprint)) {
            $socket->push($fd, json_encode([
                'type' => 'notify',
                'data' => [
                    'type' => 'bg-danger text-white',
                    'message' => 'Token inválido'
                ]
            ]));
            return $socket->push($fd, json_encode([
                'byToken' => $model['id'],
                'data' => [
                    'success' => false,
                ]
            ]));
        }
        if (!cache::get('coroutinesProcess')) {
            cache::set('coroutinesProcess', []);
        }

        $userData = $vault->get($fingerprint);
        $userCodec = $userData['codec'] ?? 'PCMA/8000';
        if (!empty($data['codec'])) $userCodec = $data['codec'];


        if (array_key_exists($fingerprint, cache::get('coroutinesProcess'))) {
            return $socket->push($fd, json_encode([
                'type' => 'notify',
                'data' => [
                    'type' => 'bg-warning text-dark',
                    'message' => 'Já existe uma chamada em andamento'
                ]
            ]));
        }


        if (!array_key_exists($fingerprint, cache::get('coroutinesProcess'))) {
            try {
                $phone = new trunkController($userData['sipUser'], $userData['sipPass'], self::parseSipServer($userData['sipServer']));
            } catch (\Exception $e) {
                $socket->push($fd, json_encode([
                    'type' => 'notify',
                    'data' => [
                        'type' => 'bg-danger text-white',
                        'message' => 'Não foi possível resolver o host fornecido'
                    ]
                ]));
                return false;
            }
            cache::subDefine('coroutinesProcess', $fingerprint, $phone);
            $socket->push($fd, json_encode([
                'type' => 'notify',
                'data' => [
                    'type' => 'bg-info text-white',
                    'message' => 'Iniciando processo de chamada'
                ]
            ]));
        }
        /** @var trunkController $phone */
        $phone = cache::get('coroutinesProcess')[$fingerprint];


        $socket->push($fd, json_encode([
            'type' => 'notify',
            'data' => [
                'type' => 'bg-primary text-white',
                'message' => 'Conectando ao servidor SIP'
            ]
        ]));
        $number = $model['data']['digits'];

        $socket->push($fd, json_encode([
            'byToken' => $model['id'],
            'data' => [
                'success' => true,
                'callId' => $phone->callId
            ]
        ]));


        if (!$phone->register(10)) {
            $phone->close();
            $socket->push($fd, json_encode([
                'byToken' => $model['id'],
                'data' => [
                    'success' => true,
                    'callId' => $phone->callId
                ]
            ]));
            $fds = (cache::get('connections')[$fingerprint] ?? []);
            foreach ($fds as $fd) {
                $socket->push($fd, json_encode([
                    'type' => 'event',
                    'data' => 'bye'
                ]));
                $socket->push($fd, json_encode([
                    'type' => 'notify',
                    'data' => [
                        'type' => 'bg-danger text-white',
                        'message' => '[SIP] Erro ao registrar'
                    ]
                ]));
            }
            cache::unset('coroutinesProcess', $fingerprint);
            return false;


        }

        $audioBuffer = '';
        $phone->onRinging(function ($phone) use ($socket, $fingerprint) {
            $fds = cache::get('connections')[$fingerprint] ?? [];
            foreach ($fds as $fd) {
                $socket->push($fd, json_encode([
                    'type' => 'notify',
                    'data' => [
                        'type' => 'bg-primary text-white',
                        'message' => 'Chamada tocando...'
                    ]
                ]));
                $socket->push($fd, json_encode([
                    'type' => 'changeCallId',
                    'data' => $phone->callId
                ]));
            }
        });
        $phone->onFailed(function ($message) use ($fd, $model, $phone, $socket, $fingerprint) {
            $socket->push($fd, json_encode([
                'byToken' => $model['id'],
                'data' => [
                    'success' => true,
                    'callId' => $phone->callId
                ]
            ]));
            $fds = cache::get('connections')[$fingerprint] ?? [];
            foreach ($fds as $fd) {
                $socket->push($fd, json_encode([
                    'type' => 'notify',
                    'data' => [
                        'type' => 'bg-danger text-white',
                        'message' => $message
                    ]
                ]));

            }
            $fds = (cache::get('connections')[$fingerprint] ?? []);
            foreach ($fds as $fd) {
                $socket->push($fd, json_encode([
                    'type' => 'event',
                    'data' => 'bye'
                ]));
                $socket->push($fd, json_encode([
                    'type' => 'notify',
                    'data' => [
                        'type' => 'bg-danger text-white',
                        'message' => '[SIP] ' . $message
                    ]
                ]));
            }
            cache::unset('coroutinesProcess', $fingerprint);
        });


        $phone->onHangup(function (trunkController $phone) use ($model, $fd, $socket, $fingerprint) {
            $socket->push($fd, json_encode([
                'byToken' => $model['id'],
                'data' => [
                    'success' => true,
                    'callId' => $phone->callId
                ]
            ]));
            $fds = cache::get('connections')[$fingerprint] ?? [];
            foreach ($fds as $fd) {
                $socket->push($fd, json_encode([
                    'type' => 'notify',
                    'data' => [
                        'type' => 'bg-danger text-white',
                        'message' => 'Chamada finalizada'
                    ]
                ]));
            }

            $phone->close();
            cache::unset('coroutinesProcess', $fingerprint);
            cli::pcl("Call stopped", "bold_red");
            $fds = (cache::get('connections')[$fingerprint] ?? []);
            foreach ($fds as $fd) {
                $socket->push($fd, json_encode([
                    'type' => 'event',
                    'data' => 'bye'
                ]));
            }
        });

        $phone->mountLineCodecSDP($userCodec);


        $callId = $phone->callId;
        $freePort = network::getFreePort();
        $eventSock = new Socket(AF_INET, SOCK_DGRAM, 0);
        $phone->saveGlobalInfo('eventSock', $eventSock);
        $phone->globalInfo['eventSock']->bind('0.0.0.0', $freePort);
        $portHandler = $phone->globalInfo['eventSock']->getsockname()['port'];



        $phone->onReceivePcm(function ($pcmData, $peer, trunkController $phone, $codec, $frequency) use ($fingerprint, $portHandler) {
            if (strlen($pcmData) < 12) return;
            $id = implode(':', array_values($peer));


            // resample


            /** @var Socket $eventSock */
            $phone->globalInfo['eventSock']
                ->sendto('127.0.0.1', 9600, "{$pcmData}__::__{$phone->callId}__::__{$id}__::__{$portHandler}__::__{$codec}__::__{$frequency}");
        });


        $phone->onAnswer(function (trunkController $phone) use ($socket, $vault, $fingerprint) {
            $phone->receiveMedia();
            cli::pcl("Recebendo audio", "green");

            Coroutine::create(function () use ($phone) {

                cli::pcl("Iniciando socket (Browser Mic → RTP)", "green");

                // handshake inicial (mantido)
                $phone->globalInfo['eventSock']->sendto(
                    '127.0.0.1',
                    9600,
                    str_repeat('0', 12)
                );

                // buffer persistente de PCM vindo do browser
                $pcmBuffer = '';

                // parâmetros VoIP
                $FRAME_MS = 20;
                $SRC_RATE = $phone->frequencyCall; // ex: 8000
                $SAMPLES_PER_FRAME = (int)($SRC_RATE * ($FRAME_MS / 1000)); // 160
                $PCM_FRAME_BYTES = $SAMPLES_PER_FRAME * 2; // PCM16 = 320 bytes

                cli::pcl(
                    "Frame VoIP: {$FRAME_MS}ms | {$SAMPLES_PER_FRAME} samples | {$PCM_FRAME_BYTES} bytes",
                    "cyan"
                );

                while (true) {

                    $peer = null;
                    $data = $phone->globalInfo['eventSock']->recvfrom($peer, 0.1);

                    // condições de saída
                    if ($phone->receiveBye) break;
                    if ($phone->error) break;
                    if (!$phone->callActive) break;

                    if (empty($data)) {
                        Coroutine::sleep(0.01);
                        continue;
                    }

                    // separa payload
                    [$pcmIn, $callId, $ssrc] = explode('__::__', $data, 3);

                    // acumula PCM do browser (NUNCA confiar no tamanho recebido)
                    $pcmBuffer .= $pcmIn;

                    // enquanto houver frame VoIP completo…
                    while (strlen($pcmBuffer) >= $PCM_FRAME_BYTES) {

                        // corta exatamente 20ms
                        $pcmChunk = substr($pcmBuffer, 0, $PCM_FRAME_BYTES);
                        $pcmBuffer = substr($pcmBuffer, $PCM_FRAME_BYTES);

                        $encode = null;

                        switch (strtoupper($phone->codecName)) {

                            case 'PCMU':
                                $encode = encodePcmToPcmu($pcmChunk);
                                break;

                            case 'PCMA':
                                $encode = encodePcmToPcma($pcmChunk);
                                break;

                            case 'G729':
                                $encode = $phone->bcgChannel->encode($pcmChunk);
                                break;

                            case 'OPUS':
                                // browser → 8k → 48k → opus
                                $pcm48 = resampler($pcmChunk, $SRC_RATE, 48000);

                                $memberKey =
                                    array_keys($phone->mediaChannel->members, $ssrc, true)[0]
                                    ?? array_key_first($phone->mediaChannel->members);

                                $encode = $phone->mediaChannel->members[$memberKey]['opus']
                                    ->encode($pcm48, $SRC_RATE);
                                break;

                            case 'L16':
                                $encode = encodePcmToL16($pcmChunk);

                                break;

                            default:
                                continue 2;
                        }

                        if (!$encode) continue;

                        // RTP
                        $packet = $phone->rtpChannel->buildAudioPacket($encode);

                        $phone->mediaChannel->socket->sendto(
                            $phone->audioRemoteIp,
                            $phone->audioRemotePort,
                            $packet
                        );


                    }
                }

                cli::pcl("Fechando socket (Browser Mic)", "red");
            });



            $datasUser = $vault->get($fingerprint);
            $datasUser['lastPacket'] = $phone->headers200;
            $vault->set($fingerprint, $datasUser);

            $fds = cache::get('connections')[$fingerprint] ?? [];
            foreach ($fds as $fd) {
                $socket->push($fd, json_encode([
                    'type' => 'notify',
                    'data' => [
                        'type' => 'bg-success text-white',
                        'message' => 'Chamada conectada com ' . $phone->calledNumber
                    ]
                ]));
            }


            $fds = (cache::get('connections')[$fingerprint] ?? []);
            foreach ($fds as $fd) {
                $socket->push($fd, json_encode([
                    'type' => 'event',
                    'data' => 'callAccept'
                ]));
            }
            cli::pcl("Chamada conectada com " . $phone->calledNumber, "green");
        });


        $codec = $phone->codecName;
        $frequency = $phone->frequencyCall;
        $phone->onKeyPress(function ($event, $peer) use ($eventSock, $callId, $portHandler, $frequency, $codec) {
            $pcmChunk = self::dtmfToScale($event);
            $id = implode(':', array_values($peer));
            if (empty($pcmChunk)) return;

            $chunks = str_split($pcmChunk, 320);
            foreach ($chunks as $pcmChunk) {
                $eventSock->sendto('127.0.0.1', 9600, "{$pcmChunk}__::__{$callId}__::__{$id}__::__{$portHandler}__::__{$codec}__::__{$frequency}");
            }


        });

        $phone->call($number);


        cli::pcl("Script finalizado", "green");
        cli::pcl("Processo cancelado", "red");
        $socket->push($fd, json_encode(['byToken' => $model['id'],
            'data' => ['success' => true,
                'callId' => $phone->callId]]));
        $phone->bye();
        cache::unset('coroutinesProcess', $fingerprint);
        unset($phone);
        $fds = (cache::get('connections')[$fingerprint] ?? []);
        foreach ($fds as $fd) {
            $socket->push($fd, json_encode([
                'type' => 'event',
                'data' => 'bye'
            ]));
            $socket->push($fd, json_encode([
                'type' => 'notify',
                'data' => [
                    'type' => 'bg-danger text-white',
                    'message' => 'Chamada finalizada'
                ]
            ]));
        }


        return true;


    }

    private static function addTimerToConnection(int $fd, int $timerId): void
    {
        if (!isset(self::$connectionTimers[$fd])) {
            self::$connectionTimers[$fd] = [];
        }
        self::$connectionTimers[$fd][] = $timerId;
    }

    private
    static function removeTimerFromConnection(int $fd, int $timerId): void
    {
        if (isset(self::$connectionTimers[$fd])) {
            $key = array_search($timerId, self::$connectionTimers[$fd]);
            if ($key !== false) {
                unset(self::$connectionTimers[$fd][$key]);
            }

            if (empty(self::$connectionTimers[$fd])) {
                unset(self::$connectionTimers[$fd]);
            }
        }
    }

    public
    static function clearConnectionTimers(int $fd): void
    {
        if (isset(self::$connectionTimers[$fd])) {
            foreach (self::$connectionTimers[$fd] as $timerId) {
                Timer::clear($timerId);
            }
            unset(self::$connectionTimers[$fd]);
        }
    }

    private
    static function parseSipServer(string $sipServer): string
    {
        $filterIp = filter_var($sipServer, FILTER_VALIDATE_IP);
        if ($filterIp) {
            return $sipServer;
        }
        return gethostbyname($sipServer);
    }

    private static function dtmfToScale(int|string $event): string
    {
        $dtmfFrequencies = [
            '1' => [697, 1209], '2' => [697, 1336], '3' => [697, 1477],
            '4' => [770, 1209], '5' => [770, 1336], '6' => [770, 1477],
            '7' => [852, 1209], '8' => [852, 1336], '9' => [852, 1477],
            '*' => [941, 1209], '0' => [941, 1336], '#' => [941, 1477],
            'A' => [697, 1633], 'B' => [770, 1633], 'C' => [852, 1633], 'D' => [941, 1633]
        ];

        $event = strtoupper((string)$event);
        if (!isset($dtmfFrequencies[$event])) {
            return '';
        }

        [$f1, $f2] = $dtmfFrequencies[$event];
        $sampleRate = 8000;
        $duration = 0.08; // 160ms
        $numSamples = $sampleRate * $duration;
        $pcm = '';

        for ($i = 0; $i < $numSamples; $i++) {
            $sample = (sin(2 * M_PI * $f1 * $i / $sampleRate) + sin(2 * M_PI * $f2 * $i / $sampleRate)) * 8000;
            $pcm .= pack('s', (int)$sample);
        }

        return $pcm;
    }
}