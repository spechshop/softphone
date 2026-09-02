<?php

require __DIR__ . '/../libspech/plugins/autoloader.php';
require_once __DIR__ . '/../plugins/Message/handlers/hangUpCall.php';

use handlers\hangUpCall;
use libspech\Cache\cache;

final class BroadcastSocketSpy
{
    public array $messages = [];

    public function push(int $fd, string $payload): bool
    {
        $this->messages[$fd][] = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        return true;
    }
}

$socket = new BroadcastSocketSpy();
cache::set('connections', [
    'same-device' => [11, 12],
    'other-device' => [21],
]);

$method = new ReflectionMethod(hangUpCall::class, 'broadcastCallEnded');
$method->invoke(null, $socket, 'same-device', 'call-123');

if (array_keys($socket->messages) !== [11, 12]) {
    throw new RuntimeException('callEnded não foi limitado a todos os FDs do fingerprint correto');
}
foreach ([11, 12] as $fd) {
    $expected = ['type' => 'callEnded', 'data' => ['callId' => 'call-123']];
    if (($socket->messages[$fd][0] ?? null) !== $expected) {
        throw new RuntimeException("payload callEnded inválido no FD {$fd}");
    }
}

echo "OK: callEnded enviado aos dois FDs do fingerprint, sem atingir outro dispositivo.\n";
