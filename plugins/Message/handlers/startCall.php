<?php

namespace handlers;

use helpers\utils\OutboundCall;
use helpers\utils\PhoneController;
use libspech\Cache\cache;
use Swoole\WebSocket\Server;

/** WebSocket adapter for the PhoneController outbound state machine. */
final class startCall
{
    public static function resolve(Server $socket, array $model, int $fd): ?bool
    {
        $data = $model['data'] ?? [];
        $fingerprint = (string)($data['fp'] ?? '');
        $vault = new \spechphoneVault(\helpers\utils\AccountIdentity::vaultPath(), getenv('SPECH_VAULT_KEY_HEX'));
        if ($fingerprint === '' || !$vault->exists($fingerprint)) {
            return self::reply($socket, $fd, $model, false, null, 'Token inválido');
        }
        $account = $vault->get($fingerprint);
        foreach (['sipServer', 'sipUser', 'sipPass'] as $required) {
            if (empty($account[$required])) {
                return self::reply($socket, $fd, $model, false, null, "Campo obrigatório não preenchido: {$required}");
            }
        }
        $processes = cache::get('coroutinesProcess') ?: [];
        if (isset($processes[$fingerprint])) {
            return self::reply($socket, $fd, $model, false, null, 'Já existe uma chamada em andamento');
        }

        try {
            $call = PhoneController::instance($socket)->createOutboundCall(
                $account,
                (string)($data['digits'] ?? ''),
                [
                    'trunkCodec' => (string)($account['trunkCodec'] ?? 'PCMA/8000'),
                    'userCodec' => (string)($data['codec'] ?? $account['codec'] ?? 'PCMA/8000'),
                    'opus' => is_array($account['opus'] ?? null) ? $account['opus'] : null,
                    'sourceSampleRate' => (int)($data['sourceSampleRate'] ?? 8000),
                    'sourceChannels' => (int)($data['sourceChannels'] ?? 1),
                ]
            );
        } catch (\Throwable) {
            return self::reply($socket, $fd, $model, false, null, 'Não foi possível iniciar a chamada SIP');
        }

        cache::subDefine('coroutinesProcess', $fingerprint, $call);
        self::notify($socket, $fingerprint, 'bg-primary text-white', 'Conectando ao servidor SIP');
        self::reply($socket, $fd, $model, true, $call->callId);

        $call->onRinging(function (OutboundCall $call) use ($socket, $fingerprint): void {
            self::notify($socket, $fingerprint, 'bg-primary text-white', 'Chamada tocando...');
            self::broadcast($socket, $fingerprint, ['type' => 'changeCallId', 'data' => $call->callId]);
        });
        $call->onAnswer(function (OutboundCall $call, array $response) use ($socket, $fingerprint, $vault): void {
            $stored = $vault->get($fingerprint);
            $response['call_id'] = $call->callId;
            $response['remote_target'] = $call->dialog->remoteTarget;
            $response['route_set'] = $call->dialog->routeSet;
            $stored['lastPacket'] = $response;
            $vault->set($fingerprint, $stored);
            self::notify($socket, $fingerprint, 'bg-success text-white', 'Chamada conectada com ' . $call->calledNumber);
            if ($call->effectiveOpusConfig() !== null) {
                self::broadcast($socket, $fingerprint, [
                    'type' => 'opusNegotiated',
                    'data' => $call->effectiveOpusConfig(),
                ]);
            }
            self::broadcast($socket, $fingerprint, ['type' => 'event', 'data' => 'callAccept']);
        });
        $call->onFailed(function (OutboundCall $call, string $reason, int $code) use ($socket, $fingerprint): void {
            $messages = [
                403 => 'Chamada recusada pelo provedor', 404 => 'Destino não encontrado',
                408 => 'Tempo limite da chamada excedido', 480 => 'Destino temporariamente indisponível',
                486 => 'Destino ocupado', 488 => 'Mídia incompatível',
                500 => 'Erro no servidor SIP', 503 => 'Serviço SIP indisponível',
            ];
            self::notify($socket, $fingerprint, 'bg-danger text-white', '[SIP] ' . ($messages[$code] ?? $reason));
        });
        $call->onTerminated(function (OutboundCall $call) use ($socket, $fingerprint): void {
            $current = cache::get('coroutinesProcess')[$fingerprint] ?? null;
            if ($current === $call) cache::unset('coroutinesProcess', $fingerprint);
            self::broadcast($socket, $fingerprint, ['type' => 'event', 'data' => 'bye']);
            self::notify($socket, $fingerprint, 'bg-danger text-white', 'Chamada finalizada');
        });

        $call->start();
        return true;
    }

    private static function reply(Server $socket, int $fd, array $model, bool $success, ?string $callId, ?string $message = null): bool
    {
        if ($message !== null) {
            $socket->push($fd, json_encode(['type' => 'notify', 'data' => [
                'type' => $success ? 'bg-info text-white' : 'bg-danger text-white', 'message' => $message,
            ]]));
        }
        return $socket->push($fd, json_encode(['byToken' => $model['id'] ?? null, 'data' => [
            'success' => $success, 'callId' => $callId, 'error' => $success ? null : $message,
        ]]));
    }

    private static function notify(Server $socket, string $fp, string $type, string $message): void
    {
        self::broadcast($socket, $fp, ['type' => 'notify', 'data' => ['type' => $type, 'message' => $message]]);
    }

    private static function broadcast(Server $socket, string $fp, array $payload): void
    {
        foreach (cache::get('connections')[$fp] ?? [] as $clientFd) {
            $socket->push($clientFd, json_encode($payload));
        }
    }
}
