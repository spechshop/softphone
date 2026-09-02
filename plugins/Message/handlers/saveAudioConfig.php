<?php
declare(strict_types=1);

namespace handlers;

use helpers\utils\AccountIdentity;
use helpers\utils\AudioConfig;
use libspech\Cache\cache;
use Swoole\WebSocket\Server;

/** Persists microphone preferences without forcing a new SIP registration. */
final class saveAudioConfig
{
    public static function resolve(Server $socket, array $model, int $fd): ?bool
    {
        $data = is_array($model['data'] ?? null) ? $model['data'] : [];
        $fingerprint = (string)($data['fp'] ?? '');
        $vault = new \spechphoneVault(AccountIdentity::vaultPath(), (string)getenv('SPECH_VAULT_KEY_HEX'));

        if ($fingerprint === '' || !$vault->exists($fingerprint)) {
            return self::reply($socket, $fd, $model, false, 'Token inválido.');
        }

        try {
            $account = $vault->get($fingerprint);
            if (!is_array($account)) {
                return self::reply($socket, $fd, $model, false, 'Conta inválida.');
            }

            $normalized = AudioConfig::normalize(
                is_array($data['audio'] ?? null) ? $data['audio'] : null,
                is_array($data['opus'] ?? null)
                    ? $data['opus']
                    : (is_array($account['opus'] ?? null) ? $account['opus'] : null),
            );
            $account['audio'] = $normalized['audio'];
            $account['opus'] = $normalized['opus'];
            $vault->set($fingerprint, $account);
            $vault->flush();

            $connections = cache::get('connections');
            $clientFds = is_array($connections) ? ($connections[$fingerprint] ?? []) : [];
            if ($clientFds === []) $clientFds = [$fd];
            foreach (array_unique(array_map('intval', $clientFds)) as $clientFd) {
                foreach (['audio', 'opus'] as $key) {
                    $socket->push($clientFd, json_encode([
                        'type' => 'setKey',
                        'key' => $key,
                        'value' => $account[$key],
                    ], JSON_UNESCAPED_SLASHES));
                }
            }

            return self::reply(
                $socket,
                $fd,
                $model,
                true,
                'Configurações de áudio salvas. Serão usadas na próxima chamada.',
                $normalized,
            );
        } catch (\Throwable $error) {
            error_log('[saveAudioConfig] ' . $error->getMessage());
            return self::reply($socket, $fd, $model, false, 'Não foi possível salvar as configurações de áudio.');
        }
    }

    private static function reply(
        Server $socket,
        int $fd,
        array $model,
        bool $success,
        string $message,
        array $extra = [],
    ): bool {
        return $socket->push($fd, json_encode([
            'byToken' => $model['id'] ?? null,
            'data' => $extra + ['success' => $success, 'message' => $message],
        ], JSON_UNESCAPED_SLASHES));
    }
}
