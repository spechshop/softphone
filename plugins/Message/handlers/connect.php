<?php

namespace handlers;


use libspech\Cache\cache;
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


        $vault = new \spechphoneVault('/data/spechphone/devices.vault', getenv('SPECH_VAULT_KEY_HEX'));


        if ($vault->exists($data['fp'])) {
            print 'vault exists' . PHP_EOL;
            $connections = cache::get('connections');
            if (!array_key_exists($data['fp'], $connections)) $connections[$data['fp']] = [];
            $connections[$data['fp']][] = $fd;
            cache::set('connections', $connections);


            $userDatas = $vault->get($data['fp']);
            foreach ($userDatas as $key => $value) {
                $socket->push($fd, json_encode([
                    'type' => 'setKey',
                    'key' => $key,
                    'value' => $value,
                ]));
            }
        }
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
        var_dump(cache::get('connections'));


        $vault = new \spechphoneVault('/data/spechphone/devices.vault', getenv('SPECH_VAULT_KEY_HEX'));

        $fds = cache::get('connections')[$data['fp']] ?? [];
        foreach ($fds as $framed) {
            $socket->push($framed, json_encode([
                'type' => 'changeCallId',
                'data' => $vault->get($data['fp'])['lastPacket']['headers']['Call-ID'][0]
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
                'byToken' => $model['id'] ?? null,
                'data' => [
                    'success' => false,
                ]
            ]));
        }
        if (!cache::get('coroutinesProcess')) {
            cache::set('coroutinesProcess', []);
        }


        $idTimer = Timer::tick(10000, function ($idTimer) use ($socket, $fd, $data) {
            $vault = new \spechphoneVault('/data/spechphone/devices.vault', getenv('SPECH_VAULT_KEY_HEX'));
            if ($vault->exists($data['fp'])) {
                $userDatas = $vault->get($data['fp']);

                $lastPacket = $userDatas['lastPacket'];
                $renderURI = $lastPacket['headers']['From'][0];

                if (!$socket->push($fd, json_encode([
                    'type' => 'brand',
                    'data' => $renderURI
                ]))) {
                    cli::pcl('Erro ao enviar mensagem para o cliente: ' . $fd);
                    return Timer::clear($idTimer);
                }
                return true;
            }
        });
        self::addTimerToConnection($fd, $idTimer);


        if ($vault->exists($data['fp'])) {
            $fds = cache::get('connections')[$data['fp']] ?? [];
            foreach ($fds as $framed) {
                $socket->push($framed, json_encode([
                    'type' => 'changeCallId',
                    'data' => $vault->get($data['fp'])['lastPacket']['headers']['Call-ID'][0]
                ]));
            }


            $userDatas = $vault->get($data['fp']);
            $lastPacket = $userDatas['lastPacket'];
            $renderURI = $lastPacket['headers']['From'][0];
            $socket->push($fd, json_encode([
                'type' => 'brand',
                'data' => $renderURI
            ]));
            $socket->push($fd, json_encode([
                'type' => 'changeCallId',
                'data' => $lastPacket['headers']['Call-ID'][0]
            ]));
        }
        Timer::after(5000, function () use ($socket, $fd, $fingerprint) {
            if (array_key_exists($fingerprint, cache::get('coroutinesProcess'))) {
                $socket->push($fd, json_encode([
                    'type' => 'event',
                    'data' => 'callAccept'
                ]));

            }
        });


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
}