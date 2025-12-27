<?php

namespace handlers;


use libspech\Cli\cli;
use Swoole\Timer;

class connect
{
    private static array $connectionTimers = [];

    public static function resolve(\Swoole\WebSocket\Server $socket, array $model, int $fd): ?bool
    {
        print 'connect' . PHP_EOL;


        self::clearConnectionTimers($fd);


        $data = $model['data'];


        if (empty($data['token'])) {
            if (empty($data['currentPage'])) {
                $currentPage = 'default';
                return $socket->push($fd, json_encode([
                    'type' => 'setPage',
                    'page' => $currentPage,
                ]));
            } else {
                $socket->push($fd, json_encode([
                    'type' => 'setPage',
                    'page' => $data['currentPage'],
                ]));
            }
            $socket->push($fd, json_encode([
                'type' => 'setKey',
                'key' => 'token',
                'value' => '.'
            ]));
        }
        $socket->push($fd, json_encode([
            'type' => 'setPage',
            'page' => $data['currentPage'],
        ]));

        $idTimer = Timer::tick(1000, function ($idTimer) use ($socket, $fd, $data) {
            $vault = new \spechphoneVault('/data/spechphone/devices.vault', getenv('SPECH_VAULT_KEY_HEX'));
            if ($vault->exists($data['fp'])) {
                $userDatas = $vault->get($data['fp']);
                $lastPacket = $userDatas['lastPacket'];
                $renderURI = $lastPacket['headers']['From'][0];

                if (!$socket->push($fd, json_encode([
                        'type' => 'brand',
                        'data' => $renderURI
                    ]))) {
                    cli::pcl('Erro ao enviar mensagem para o cliente: '.$fd);
                   return Timer::clear($idTimer);
                }
                return true;
            }
        });
        self::addTimerToConnection($fd, $idTimer);


        return true;
    }

    private static function addTimerToConnection(int $fd, int $timerId): void
    {
        if (!isset(self::$connectionTimers[$fd])) {
            self::$connectionTimers[$fd] = [];
        }
        self::$connectionTimers[$fd][] = $timerId;
    }

    private static function removeTimerFromConnection(int $fd, int $timerId): void
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

    public static function clearConnectionTimers(int $fd): void
    {
        if (isset(self::$connectionTimers[$fd])) {
            foreach (self::$connectionTimers[$fd] as $timerId) {
                Timer::clear($timerId);
            }
            unset(self::$connectionTimers[$fd]);
        }
    }
}