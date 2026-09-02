<?php

namespace handlers;

use helpers\utils\Registrar;
use helpers\utils\SipRegisterManager;
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
        $data = $model['data'];
        $vault = new \spechphoneVault('/data/spechphone/devices.vault', getenv('SPECH_VAULT_KEY_HEX'));
        if (empty($data['fp'])) {
            return self::respond($socket, $fd, $model, false, 'Identificador do dispositivo ausente.');
        }
        $fingerprint = $data['fp'];
        $previousData = $vault->exists($fingerprint) ? $vault->get($fingerprint) : null;

        if (!$vault->exists($fingerprint)) {
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
                return self::respond(
                    $socket,
                    $fd,
                    $model,
                    false,
                    'Campo obrigatório não preenchido: ' . $input
                );
            }
        }

        try {
            $endpoint = SipRegisterManager::parseEndpoint((string)$data['sipServer']);
            $data['sipServer'] = $endpoint['host']
                . ($endpoint['port'] === SipRegisterManager::DEFAULT_REMOTE_PORT ? '' : ':' . $endpoint['port']);
        } catch (\Throwable) {
            return self::respond($socket, $fd, $model, false, 'Servidor SIP inválido.');
        }

        $result = Registrar::registerOneDetailed($socket, $fingerprint, $data);
        if (!$result['success']) {
            return self::respond($socket, $fd, $model, false, Registrar::messageForResult($result), [
                'reason' => $result['reason'],
                'code' => $result['code'],
            ]);
        }

        // Persist only after the provider has confirmed this transaction with
        // a correlated 200 OK. Challenges/credentials never enter lastPacket.
        $data['lastPacket'] = $result['response'] ?? [];
        $vault->set($fingerprint, $data);
        $vault->flush();
        if (is_array($previousData)
            && !empty($previousData['sipServer'])
            && !empty($previousData['sipUser'])
            && !empty($previousData['sipPass'])
            && (
                $previousData['sipServer'] !== $data['sipServer']
                || $previousData['sipUser'] !== $data['sipUser']
            )
        ) {
            // The new account is already confirmed, so it is now safe to
            // remove the old provider binding without risking loss of service.
            SipRegisterManager::register($socket, $previousData, 0, 5.0);
        }
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
            'value' => $data['lastPacket']
        ]));
        return self::respond($socket, $fd, $model, true, 'Registro confirmado e configuração salva.', [
            'reason' => 'registered',
            'code' => 200,
            'contactPort' => SipRegisterManager::SIP_PORT,
            'bindingConfirmed' => (bool)($result['binding_confirmed'] ?? false),
        ]);
    }

    private static function respond(
        Server $socket,
        int $fd,
        array $model,
        bool $success,
        string $message,
        array $extra = []
    ): bool {
        $socket->push($fd, json_encode([
            'type' => 'notify',
            'data' => [
                'type' => $success ? 'bg-success text-white' : 'bg-danger text-white',
                'message' => $message,
            ],
        ]));
        return $socket->push($fd, json_encode([
            'byToken' => $model['id'] ?? null,
            'data' => $extra + ['success' => $success, 'message' => $message],
        ]));
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
        return SipRegisterManager::parseEndpoint($sipServer)['host'];
    }
}
