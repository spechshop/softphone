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

        $phone->mountLineCodecSDP('PCMA/8000');


        $callId = $phone->callId;
        $freePort = network::getFreePort();
        $eventSock = new Socket(AF_INET, SOCK_DGRAM, 0);
        $phone->saveGlobalInfo('eventSock', $eventSock);
        $phone->globalInfo['eventSock']->bind('0.0.0.0', $freePort);
        $portHandler = $phone->globalInfo['eventSock']->getsockname()['port'];


        $phone->onReceivePcm(function ($pcmData, $peer, trunkController $phone, $codec, $frequency) use ($fingerprint, $portHandler) {
            if (strlen($pcmData) < 12) return;
            $id = implode(':', array_values($peer));
            //cli::pcl($codec . ' ' . $frequency, "yellow");
            // resample




            /** @var Socket $eventSock */
            $phone->globalInfo['eventSock']
                ->sendto('127.0.0.1', 9600, "{$pcmData}__::__{$phone->callId}__::__{$id}__::__{$portHandler}__::__{$codec}__::__{$frequency}");
        });


        $phone->onAnswer(function (trunkController $phone) use ($socket, $vault, $fingerprint) {
            $phone->receiveMedia();

            Coroutine::create(function () use ($phone) {
                while (true) {
                    $peer = null;
                    $data = $phone->globalInfo['eventSock']->recvfrom($peer, 0.1);
                    if ($phone->receiveBye) break;
                    if ($phone->error) break;
                    if (!$phone->callActive) break;


                    if ($data === false) {
                        continue;
                    }

                    $frequencyPacket = 8000;
                    $frequencyMember = 8000;
                    [$pcmChunk, $callId, $ssrc] = explode('__::__', $data);


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
                            $pcm48 = resampler($pcmChunk, $frequencyPacket, 48000);
                            $encode = $phone->mediaChannel->members
                            [array_keys($phone->mediaChannel->members, $ssrc, true)[0] ?? array_key_first($phone->mediaChannel->members)]
                            ['opus']
                                ->encode($pcm48, $phone->frequencyCall);
                            break;
                        case 'L16':
                            $encode = resampler($pcmChunk, $frequencyPacket, $frequencyMember, true);
                            break;

                        default:
                            return;
                    }

                    if (!$encode) return;
                    $packet = $phone->rtpChannel->buildAudioPacket($encode);
                    $phone->mediaChannel->socket->sendto($phone->audioRemoteIp, $phone->audioRemotePort, $packet);


                }
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


        $phone->onKeyPress(function ($event, $peer) use ($eventSock, $callId, $portHandler) {
            $pcmChunk = self::dtmfToScale($event);
            $id = implode(':', array_values($peer));
            $eventSock->sendto('127.0.0.1', 9600, "{$pcmChunk}__::__{$callId}__::__{$id}__::__{$portHandler}");
        });
        $phone->call($number);


        cli::pcl("Script finalizado", "green");
        cli::pcl("Processo cancelado", "red");
        $socket->push($fd, json_encode(['byToken' => $model['id'],
            'data' => ['success' => true,
                'callId' => $phone->callId]]));
        $phone->close();

        return true;


    }

    private
    static function addTimerToConnection(int $fd, int $timerId): void
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

    private
    static function dtmfToScale(int|string $event): string
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