<?php

namespace handlers;


use helpers\utils\CallState;
use libspech\Cli\cli;
use libspech\Network\network;
use libspech\Sip\sip;
use Random\RandomException;
use Swoole\Timer;

class register
{
    private static array $connectionTimers = [];

    public static function resolve(\Swoole\WebSocket\Server $socket, array $model, int $fd): ?bool
    {
        print 'register' . PHP_EOL;


        self::clearConnectionTimers($fd);
        $data = $model['data'];


        $vault = new \spechphoneVault('/data/spechphone/devices.vault', getenv('SPECH_VAULT_KEY_HEX'));
        if (!$vault->exists($data['fp'])) return false;

        $userDatas = $vault->get($data['fp']);
        $sipServer = $userDatas['sipServer'];
        $sipUser = $userDatas['sipUser'];
        $sipPass = $userDatas['sipPass'];
        $needInputs = ['sipServer', 'sipUser', 'sipPass'];
        foreach ($needInputs as $input) {
            if (empty($userDatas[$input])) {
                return false;
            }
        }

        try {
            $phone = new \libspech\Sip\trunkController($sipUser, $sipPass, $sipServer);
        } catch (RandomException $e) {
            return false;
        }

        cli::pcl("Registrando no servidor: $sipServer", 'blue');


        $modelRegister = $phone->modelRegister(1800);
        $modelRegister['headers']['Via'][] = "SIP/2.0/UDP " . network::getLocalIp() . ":$phone->socketPortListen;branch=z9hG4bK$phone->callId;rport";


        $socket->sendto($phone->host, $phone->port, sip::renderSolution($modelRegister));
        for (; ;) {
            $peer = [];
            $res = $phone->socket->recvfrom($peer, 5);
            $receive = sip::parse($res);


            $wwwAuthenticate = $receive["headers"]["WWW-Authenticate"][0];
            $nonce = value($wwwAuthenticate, 'nonce="', '"');
            $realm = value($wwwAuthenticate, 'realm="', '"');
            $response = sip::generateAuthorizationHeader($phone->username, $realm, $phone->password, $nonce, 'sip:' . $phone->host, 'REGISTER');
            $modelRegister['headers']['Authorization'][0] = $response;
            $socket->sendto($phone->host, $phone->port, sip::renderSolution($modelRegister));
            break;
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
        $phone->close();
        return true;
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
}