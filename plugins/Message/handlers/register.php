<?php

namespace handlers;

use helpers\utils\Registrar;
use helpers\utils\SipRegisterManager;

class register
{
    public static function resolve(\Swoole\WebSocket\Server $socket, array $model, int $fd): ?bool
    {
        $fp = trim((string)($model['data']['fp'] ?? ''));
        $vault = new \spechphoneVault('/data/spechphone/devices.vault', getenv('SPECH_VAULT_KEY_HEX'));
        if ($fp === '' || !$vault->exists($fp)) {
            return self::respond($socket, $fd, $model, false, 'Configuração SIP não encontrada.', [
                'reason' => 'invalid_configuration',
            ]);
        }

        $data = $vault->get($fp);
        $result = Registrar::registerOneDetailed($socket, $fp, $data);
        if (!$result['success']) {
            return self::respond($socket, $fd, $model, false, Registrar::messageForResult($result), [
                'reason' => $result['reason'],
                'code' => $result['code'],
            ]);
        }

        return self::respond($socket, $fd, $model, true, 'Registro SIP confirmado.', [
            'reason' => 'registered',
            'code' => 200,
            'contactPort' => SipRegisterManager::SIP_PORT,
            'bindingConfirmed' => (bool)($result['binding_confirmed'] ?? false),
        ]);
    }

    private static function respond(
        \Swoole\WebSocket\Server $socket,
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

    public static function clearConnectionTimers(int $fd): void
    {
        // Renewal belongs to Registrar and is intentionally independent from
        // browser connections.
    }
}
