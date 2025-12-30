<?php

namespace handlers;


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
        $needInputs = ['sipServer', 'sipUser', 'sipPass'];
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
        $sipUser = $data['sipUser'];
        $sipPass = $data['sipPass'];
        try {
            $trunkController = new trunkController($sipUser, $sipPass, $sipServer);
        } catch (\Exception $e) {
            return $socket->push($fd, json_encode([
                'type' => 'notify',
                'data' => [
                    'type' => 'bg-danger text-white',
                    'message' => "Não foi possível resolver o host fornecido"
                ]
            ]));
        }
        $trunkController->expires = 1800;

        if (!$trunkController->register(5)) {
            var_dump($trunkController->isRegistered, $trunkController->register(5));
            return $socket->push($fd, json_encode([
                'type' => 'notify',
                'data' => [
                    'type' => 'bg-danger text-white',
                    'message' => "Registro falhou, verifique as credenciais fornecidas"
                ]
            ]));
        }
        $modelOptions = $trunkController->modelOptions();
        $trunkController->socket->sendto($trunkController->host, $trunkController->port, sip::renderSolution($modelOptions));
        $res = $trunkController->socket->recvPacket(5);
        $trunkController->close();
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

    private static function parseSipServer(string $sipServer): string
    {
        $filterIp = filter_var($sipServer, FILTER_VALIDATE_IP);
        if ($filterIp) {
            return $sipServer;
        }
        return gethostbyname($sipServer);
    }
}