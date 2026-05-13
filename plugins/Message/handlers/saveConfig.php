<?php

namespace handlers;


use libspech\Cli\cli;
use libspech\Network\network;
use libspech\Sip\sip;
use libspech\Sip\trunkController;
use Swoole\Timer;
use Swoole\WebSocket\Server;

class saveConfig
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
        print 'saveconfig' . PHP_EOL;
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

        $message = false;
        if (!$vault->exists($fingerprint)) {
            $vault->set($fingerprint, $data);
            $socket->push($fd, json_encode([
                'type' => 'notify',
                'data' => [
                    'type' => 'bg-primary text-white',
                    'message' => 'Primeiro acesso detectado, registrando token único para este dispositivo.'
                ]
            ]));
        }
        $socket->push($fd, json_encode([
            'type' => 'notify',
            'data' => [
                'type' => 'bg-primary text-white',
                'message' => 'Verificando registro...'
            ]
        ]));
        $needInputs = ['sipServer', 'sipUser', 'sipPass', 'codec', 'trunkCodec'];
        foreach ($needInputs as $input) {
            if (empty($data[$input])) {
                return $socket->push($fd, json_encode([
                    'type' => 'notify',
                    'data' => [
                        'type' => 'bg-danger text-white',
                        'message' => 'Campo obrigatório não preenchido: ' . $input
                    ]
                ]));
            }
        }


        $sipServer = self::parseSipServer($data['sipServer']);
        $data['sipServer'] = $sipServer;
        $sipUser = $data['sipUser'];
        $sipPass = $data['sipPass'];
        try {
            $phone = new trunkController($sipUser, $sipPass, $sipServer);
        } catch (\Exception $e) {
            cli::pcl("[REGISTRAR] Falha ao instanciar trunkController para {$sipUser}@{$sipServer}: " . $e->getMessage(), 'red');
            return $socket->push($fd, json_encode([
                'type' => 'notify',
                'data' => [
                    'type' => 'bg-danger text-white',
                    'message' => "Não foi possível resolver o host fornecido"
                ]
            ]));
        }
        $modelRegister = $phone->modelRegister('1800');
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
        $vault->set($fingerprint, $data);


        $modelOptions = $phone->modelOptions();
        $modelOptions['headers']['Via'][] = "SIP/2.0/UDP " . network::getLocalIp() . ":$phone->socketPortListen;branch=z9hG4bK$phone->callId;rport";


        $socket->sendto($phone->host, $phone->port, sip::renderSolution($modelOptions));
        $res = $phone->socket->recvPacket(5);
        $phone->close();
        if (!$res) {
            $socket->push($fd, json_encode([
                'type' => 'notify',
                'data' => [
                    'type' => 'bg-warning text-white',
                    'message' => "Servidor não suporta OPTIONS, registrando sem opções"
                ]
            ]));
            $vault->set($fingerprint, $data);
            foreach ($needInputs as $input) {
                $socket->push($fd, json_encode([
                    'type' => 'setKey',
                    'key' => $input,
                    'value' => $data[$input]
                ]));
            }
            $socket->push($fd, json_encode([
                'type' => 'notify',
                'data' => [
                    'type' => 'bg-warning text-white',
                    'message' => "Configuração salva com sucesso"
                ]
            ]));
            return true;
        }


        $parsedRes = sip::parse($res);
        $data['lastPacket'] = $parsedRes;


        $vault->set($fingerprint, $data);


        $socket->push($fd, json_encode([
            'type' => 'notify',
            'data' => [
                'type' => 'bg-success text-white',
                'message' => "Registro bem sucedido"
            ]
        ]));
        $vault->set($fingerprint, $data);
        foreach ($needInputs as $input) {
            $socket->push($fd, json_encode([
                'type' => 'setKey',
                'key' => $input,
                'value' => $data[$input]
            ]));
        }
        $socket->push($fd, json_encode([
            'type' => 'setKey',
            'key' => 'lastPacket',
            'value' => $parsedRes
        ]));
        $socket->push($fd, json_encode([
            'type' => 'notify',
            'data' => [
                'type' => 'bg-success text-white',
                'message' => "Configuração salva com sucesso"
            ]
        ]));
        $vault->flush();

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

    public static function parseSipServer(string $sipServer): string
    {
        $filterIp = filter_var($sipServer, FILTER_VALIDATE_IP);
        if ($filterIp) {
            return $sipServer;
        }
        $sipServer = parse_url($sipServer, PHP_URL_HOST);
        if (!$sipServer) {
            cli::pcl("[REGISTRAR] Falha ao obter host do servidor SIP: {$sipServer}", 'red');
            return '';
        }
        return gethostbyname($sipServer);
    }
}