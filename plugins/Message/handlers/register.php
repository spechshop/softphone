<?php

namespace handlers;


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

        try {
            $phone = new \libspech\Sip\trunkController($sipUser, $sipPass, $sipServer);
        } catch (RandomException $e) {
            return false;
        }
        $modelRegister = $phone->modelRegister();
        $uriContact = sip::extractURI($modelRegister['headers']['Contact'][0]);
        $uriContact['peer']['host'] = network::getLocalIp();
        $uriContact['peer']['port'] = 4000;
        $modelRegister['headers']['Contact'][0] = sip::renderURI($uriContact);
        $modelRegister['headers']['Via'][0] .= ';rport';
        $modelRegister['headers']['Expires'][0] = '3600';
        $modelRegister['headers']['User-Agent'][0] = 'SPECHPHONE SERVER';

        $phone->socket->sendto($phone->host, $phone->port, sip::renderSolution($modelRegister));
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