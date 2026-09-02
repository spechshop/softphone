<?php

namespace handlers;


use helpers\utils\CallState;
use helpers\utils\AccountIdentity;
use helpers\utils\Registrar;
use helpers\utils\OpusConfig;
use libspech\Cache\cache;
use libspech\Cli\cli;
use Swoole\Timer;

class connect
{
    private static array $connectionTimers = [];

    public static function resolve(\Swoole\WebSocket\Server $socket, array $model, int $fd): ?bool
    {
        self::clearConnectionTimers($fd);


        $data = $model['data'] ?? [];
        $data['fp'] = (string)($data['fp'] ?? '');
        $data['currentPage'] = (string)($data['currentPage'] ?? 'default');
        $data['token'] = (string)($data['token'] ?? '');


        $vault = new \spechphoneVault(AccountIdentity::vaultPath(), getenv('SPECH_VAULT_KEY_HEX'));


        if ($vault->exists($data['fp'])) {
            $connections = cache::get('connections');
            if (!array_key_exists($data['fp'], $connections)) $connections[$data['fp']] = [];
            if (!in_array($fd, $connections[$data['fp']], true)) $connections[$data['fp']][] = $fd;
            cache::set('connections', $connections);
            cli::pcl("[WS:CONNECT] accountId={$data['fp']} fd={$fd} tabs=" . count($connections[$data['fp']]), 'green');

            $userDatas = $vault->get($data['fp']);
            if (str_starts_with(strtoupper((string)($userDatas['trunkCodec'] ?? '')), 'OPUS/')
                && !is_array($userDatas['opus'] ?? null)) {
                $userDatas['opus'] = OpusConfig::defaults();
            }
            $identity = AccountIdentity::fromData((string)$data['fp'], $userDatas);
            $userDatas = array_merge($userDatas, $identity);
            $vault->set((string)$data['fp'], $userDatas);
            $vault->flush();


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
                $socket->push($fd, json_encode([
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

        if (!empty(cache::get('interface')['pages'][$data['currentPage']] ?? false)) {
            $socket->push($fd, json_encode([
                'type' => 'setPage',
                'page' => $data['currentPage'],
            ]));
        } else {
            $socket->push($fd, json_encode([
                'type' => 'setPage',
                'page' => 'default',
            ]));
        }


        $vault = new \spechphoneVault(AccountIdentity::vaultPath(), getenv('SPECH_VAULT_KEY_HEX'));

        $fds = cache::get('connections')[$data['fp']] ?? [];
        foreach ($fds as $framed) {
            $socket->push($framed, json_encode([
                'type' => 'changeCallId',
                'data' => $vault->get($data['fp'])['lastPacket']['headers']['Call-ID'][0] ?? ''
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
            if (!$socket->isEstablished($fd)) {
                return Timer::clear($idTimer);
            }
            $vault = new \spechphoneVault(AccountIdentity::vaultPath(), getenv('SPECH_VAULT_KEY_HEX'));
            if ($vault->exists($data['fp'])) {
                $userDatas = $vault->get($data['fp']);
                $Rules = ['sipServer', 'sipUser', 'sipPass'];
                 if (array_any($Rules, fn($rule) => empty($userDatas[$rule]))) {
                     return $socket->push($fd, json_encode([
                         'type' => 'notify',
                         'data' => [
                             'type' => 'bg-danger text-white',
                             'message' => 'Clique em Config. e adicione os dados do seu servidor SIP'
                         ]
                     ]));
                }


                $lastPacket = $userDatas['lastPacket'];
                $renderURI = $lastPacket['headers']['From'][0] ?? '';


                if (!$socket->push($fd, json_encode([
                    'type' => 'brand',
                    'data' => $renderURI
                ]))) {
                    cli::pcl('Erro ao enviar mensagem para o cliente: ' . $fd);
                    return Timer::clear($idTimer);
                }
                return true;
            } else {
                return Timer::clear($idTimer);
            }
        });
        self::addTimerToConnection($fd, $idTimer);


        if ($vault->exists($data['fp'])) {
            $fds = cache::get('connections')[$data['fp']] ?? [];
            foreach ($fds as $framed) {
                $socket->push($framed, json_encode([
                    'type' => 'changeCallId',
                    'data' => $vault->get($data['fp'])['lastPacket']['headers']['Call-ID'][0] ?? ''
                ]));
            }


            $userDatas = $vault->get($data['fp']);
            $lastPacket = $userDatas['lastPacket'] ?? [];
            $renderURI = $lastPacket['headers']['From'][0] ?? '';
            $socket->push($fd, json_encode([
                'type' => 'brand',
                'data' => $renderURI
            ]));
            $socket->push($fd, json_encode([
                'type' => 'changeCallId',
                'data' => $lastPacket['headers']['Call-ID'][0] ?? ''
            ]));
        }
        Timer::after(1000, function () use ($socket, $fd, $fingerprint) {
            if (array_key_exists($fingerprint, cache::get('coroutinesProcess'))) {
                $socket->push($fd, json_encode([
                    'type' => 'event',
                    'data' => 'callAccept'
                ]));
            }

            $ringingCall = \helpers\utils\CallState::findIncomingCallByFp($fingerprint);
            if ($ringingCall && $ringingCall['status'] === 'ringing') {
                $socket->push($fd, json_encode([
                    'type' => 'incomingCall',
                    'data' => [
                        'callId' => $ringingCall['call_id'],
                        'from' => $ringingCall['from_uri'],
                        'to' => $ringingCall['to_uri'],
                        'codec' => $ringingCall['codec'],
                    ],
                ]));
            }
        });
        $vault = new \spechphoneVault(AccountIdentity::vaultPath(), getenv('SPECH_VAULT_KEY_HEX'));
        if (!$vault->exists($data['fp'])) return false;
        $Rules = ['sipServer', 'sipUser', 'sipPass'];
        $userDatas = $vault->get($data['fp']);
        if (array_any($Rules, fn($rule) => empty($userDatas[$rule]))) {
            return false;
        }

        // Reconnect validates registration through the same :4000 transaction
        // manager. Periodic renewal remains server-owned and keeps working with
        // every browser closed.
        $result = Registrar::registerOneDetailed($socket, $data['fp'], $userDatas);
        if ($socket->isEstablished($fd)) {
            $socket->push($fd, json_encode([
                'type' => 'registrationState',
                'data' => [
                    'success' => (bool)$result['success'],
                    'reason' => $result['reason'],
                    'code' => $result['code'],
                    'message' => $result['success']
                        ? 'Registro SIP confirmado.'
                        : Registrar::messageForResult($result),
                ],
            ]));
        }
        return (bool)$result['success'];
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
