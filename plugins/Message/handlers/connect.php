<?php

namespace handlers;


use helpers\utils\CallState;
use libspech\Cache\cache;
use libspech\Cli\cli;
use libspech\Network\network;
use libspech\Sip\sip;
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

            if (!empty($userDatas['sipUser']) && CallState::$sipBindings !== null) {
                CallState::$sipBindings->set($userDatas['sipUser'], [
                    'fp' => $data['fp'],
                    'sip_user' => $userDatas['sipUser'],
                    'sip_server' => $userDatas['sipServer'] ?? '',
                    'sip_domain' => $userDatas['sipServer'] ?? '',
                    'contact_port' => 4000,
                    'registered_at' => time(),
                    'expires_at' => time() + 3600,
                ]);
            }

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

        if (cache::get('interface')['pages'][$data['currentPage']]) {
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


        $vault = new \spechphoneVault('/data/spechphone/devices.vault', getenv('SPECH_VAULT_KEY_HEX'));

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
            $vault = new \spechphoneVault('/data/spechphone/devices.vault', getenv('SPECH_VAULT_KEY_HEX'));
            if ($vault->exists($data['fp'])) {
                $userDatas = $vault->get($data['fp']);

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
        $vault = new \spechphoneVault('/data/spechphone/devices.vault', getenv('SPECH_VAULT_KEY_HEX'));
        if (!$vault->exists($data['fp'])) return false;
        $Rules = ['sipServer', 'sipUser', 'sipPass'];
        foreach ($Rules as $rule) {
            if (empty($data[$rule])) return false;
        }

        $userDatas = $vault->get($data['fp']);
        $sipServer = $userDatas['sipServer'];
        $sipUser = $userDatas['sipUser'];
        $sipPass = $userDatas['sipPass'];

        try {
            $phone = new \libspech\Sip\trunkController($sipUser, $sipPass, $sipServer);
        } catch (\Throwable $e) {
            return false;
        }
        $modelRegister = $phone->modelRegister(1800);
        $modelRegister['headers']['Via'][] = "SIP/2.0/UDP " . network::getLocalIp() . ":$phone->socketPortListen;branch=z9hG4bK$phone->callId;rport";
        $socket->sendto($phone->host, $phone->port, sip::renderSolution($modelRegister));
        for ($n = 3; $n--;) {
            $peer = [];
            $res = $phone->socket->recvfrom($peer, 5);
            $receive = sip::parse($res);
            if ($receive['method'] == '401') {
                $wwwAuthenticate = $receive["headers"]["WWW-Authenticate"][0];
                $nonce = value($wwwAuthenticate, 'nonce="', '"');
                $realm = value($wwwAuthenticate, 'realm="', '"');
                $response = sip::generateAuthorizationHeader($phone->username, $realm, $phone->password, $nonce, 'sip:' . $phone->host, 'REGISTER');
                $modelRegister['headers']['Authorization'][0] = $response;
                $socket->sendto($phone->host, $phone->port, sip::renderSolution($modelRegister));
            } elseif ($receive['method'] == '200') {
                break;
            } else {
                $phone->close();
                return $socket->push($fd, json_encode([
                    'type' => 'notify',
                    'data' => [
                        'type' => 'bg-danger text-white',
                        'message' => "Registro falhou [$receive[methodForParser], verifique as credenciais fornecidas"
                    ]
                ]));
            }
        }

        if (CallState::$sipBindings !== null) {
            CallState::$sipBindings->set($sipUser, [
                'fp' => $data['fp'],
                'sip_user' => $sipUser,
                'sip_server' => $sipServer,
                'sip_domain' => $sipServer,
                'contact_port' => 4000,
                'registered_at' => time(),
                'expires_at' => time() + 1800,
            ]);
        }

        // Re-register every 1500 s (25 min) to refresh before the 1800 s expiry
        $reRegFp = $data['fp'];
        $idReRegister = Timer::tick(1500 * 1000, function () use ($socket, $fd, $reRegFp) {
            $model = ['id' => uniqid('rereg_', true), 'type' => 'register', 'data' => ['fp' => $reRegFp]];
            \handlers\register::resolve($socket, $model, $fd);
            cli::pcl("[REGISTER] Re-registro automático fp:{$reRegFp}", 'cyan');
        });
        self::addTimerToConnection($fd, $idReRegister);

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
}