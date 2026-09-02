<?php

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\Socket;

require __DIR__ . '/../plugins/Request/components/spechphoneVault.php';

foreach (file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(ltrim($line), '#') || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    putenv(trim($key) . '=' . trim(trim($value), "'\""));
}

function wsClient(): Client
{
    $client = new Client('127.0.0.1', 8443, true);
    $client->set(['timeout' => 0.2, 'ssl_verify_peer' => false, 'ssl_allow_self_signed' => true]);
    if (!$client->upgrade('/')) throw new RuntimeException('falha no upgrade WebSocket');
    return $client;
}

function receiveWs(Client $client, callable $match, float $timeout = 5.0): ?array
{
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $frame = $client->recv(0.2);
        if (!$frame || !isset($frame->data)) continue;
        $message = json_decode($frame->data, true);
        if (is_array($message) && $match($message)) return $message;
    }
    return null;
}

function sipPacket(string $method, string $user, string $domain, int $port, string $callId, string $branch, string $body = ''): string
{
    $headers = [
        "Via: SIP/2.0/UDP 127.0.0.1:{$port};branch={$branch};rport",
        'From: <sip:lifecycle-test@example.test>;tag=lifecycle-test',
        "To: <sip:{$user}@{$domain}>",
        "Call-ID: {$callId}",
        "Contact: <sip:lifecycle-test@127.0.0.1:{$port}>",
        'Max-Forwards: 70',
        "CSeq: 1 {$method}",
    ];
    if ($body !== '') $headers[] = 'Content-Type: application/sdp';
    $headers[] = 'Content-Length: ' . strlen($body);
    return "{$method} sip:{$user}@127.0.0.1:4000 SIP/2.0\r\n" . implode("\r\n", $headers) . "\r\n\r\n{$body}";
}

function receiveSip(Socket $socket, callable $match, float $timeout = 5.0): ?string
{
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $peer = [];
        $packet = $socket->recvfrom($peer, 0.2);
        if (is_string($packet) && $match($packet)) return $packet;
    }
    return null;
}

function respondOkToBye(Socket $socket, string $bye): void
{
    $wanted = ['via', 'from', 'to', 'call-id', 'cseq'];
    $headers = [];
    foreach (preg_split('/\r?\n/', $bye) as $line) {
        if (!str_contains($line, ':')) continue;
        [$name] = explode(':', $line, 2);
        if (in_array(strtolower($name), $wanted, true)) $headers[] = $line;
    }
    $response = "SIP/2.0 200 OK\r\n" . implode("\r\n", $headers) . "\r\nContent-Length: 0\r\n\r\n";
    $socket->sendto('127.0.0.1', 4000, $response);
}

$exitCode = 1;
Coroutine\run(function () use (&$exitCode): void {
    // The application falls back to this project-local vault when /data is not writable.
    $vault = new spechphoneVault(__DIR__ . '/../devices.vault', (string)getenv('SPECH_VAULT_KEY_HEX'));
    $fingerprint = $vault->keys()[0] ?? null;
    $account = $fingerprint ? $vault->get($fingerprint) : null;
    if (!$fingerprint || empty($account['sipUser'])) throw new RuntimeException('dispositivo SIP não disponível no vault');
    $user = $account['sipUser'];
    $domain = $account['sipDomain'] ?? $account['sipServer'] ?? 'spechshop.com';

    $tab1 = wsClient();
    $tab2 = wsClient();
    $connect = ['type' => 'connect', 'data' => ['fp' => $fingerprint, 'token' => '.', 'currentPage' => 'default']];
    $tab1->push(json_encode($connect));
    $tab2->push(json_encode($connect));
    Coroutine::sleep(0.2);

    $caller = new Socket(AF_INET, SOCK_DGRAM, SOL_UDP);
    $caller->bind('127.0.0.1', 0);
    $callerPort = $caller->getsockname()['port'];
    $callId = 'local-hangup-' . bin2hex(random_bytes(6));
    $sdp = "v=0\r\no=test 1 1 IN IP4 127.0.0.1\r\ns=test\r\nc=IN IP4 127.0.0.1\r\nt=0 0\r\nm=audio 50000 RTP/AVP 8 101\r\na=rtpmap:8 PCMA/8000\r\na=rtpmap:101 telephone-event/8000\r\n";
    $caller->sendto('127.0.0.1', 4000, sipPacket('INVITE', $user, $domain, $callerPort, $callId, 'z9hG4bK-local-hangup', $sdp));

    $ringing = receiveSip($caller, fn(string $packet) => str_starts_with($packet, 'SIP/2.0 180'));
    $incoming1 = receiveWs($tab1, fn(array $m) => ($m['type'] ?? '') === 'incomingCall' && ($m['data']['callId'] ?? '') === $callId);
    $incoming2 = receiveWs($tab2, fn(array $m) => ($m['type'] ?? '') === 'incomingCall' && ($m['data']['callId'] ?? '') === $callId);
    if (!$ringing || !$incoming1 || !$incoming2) throw new RuntimeException('INVITE não tocou nas duas abas');

    $tab1->push(json_encode(['id' => 'accept-test', 'type' => 'callAccept', 'data' => ['fp' => $fingerprint, 'callId' => $callId]]));
    $ok = receiveSip($caller, fn(string $packet) => str_starts_with($packet, 'SIP/2.0 200'));
    if (!$ok) throw new RuntimeException('callAccept não gerou 200 OK');

    $tab1->push(json_encode(['id' => 'hangup-test', 'type' => 'HangUpCall', 'data' => [
        'fp' => $fingerprint, 'hangup' => true, 'callId' => $callId,
    ]]));
    $bye = receiveSip($caller, fn(string $packet) => str_starts_with($packet, 'BYE '));
    $ended1 = receiveWs($tab1, fn(array $m) => ($m['type'] ?? '') === 'callEnded' && ($m['data']['callId'] ?? '') === $callId);
    $ended2 = receiveWs($tab2, fn(array $m) => ($m['type'] ?? '') === 'callEnded' && ($m['data']['callId'] ?? '') === $callId);
    $success = receiveWs($tab1, fn(array $m) => ($m['byToken'] ?? '') === 'hangup-test' && ($m['data']['success'] ?? false));
    if (!$bye || !$ended1 || !$ended2 || !$success) {
        throw new RuntimeException('hangup local não completou BYE, broadcast multiaba e success');
    }
    respondOkToBye($caller, $bye);

    // Uma nova chamada para o mesmo dispositivo prova que CallState/coroutine não ficaram ocupados.
    $nextCallId = 'after-local-hangup-' . bin2hex(random_bytes(6));
    $caller->sendto('127.0.0.1', 4000, sipPacket('INVITE', $user, $domain, $callerPort, $nextCallId, 'z9hG4bK-after-hangup', $sdp));
    $nextRinging = receiveSip($caller, fn(string $packet) => str_starts_with($packet, 'SIP/2.0 180'));
    $nextIncoming = receiveWs($tab1, fn(array $m) => ($m['type'] ?? '') === 'incomingCall' && ($m['data']['callId'] ?? '') === $nextCallId);
    if (!$nextRinging || !$nextIncoming) throw new RuntimeException('estado residual bloqueou a chamada seguinte');
    $tab1->push(json_encode(['id' => 'reject-next', 'type' => 'callReject', 'data' => ['fp' => $fingerprint, 'callId' => $nextCallId]]));
    $busy = receiveSip($caller, fn(string $packet) => str_starts_with($packet, 'SIP/2.0 486'));
    if (!$busy) throw new RuntimeException('cleanup da chamada seguinte falhou');

    // Fluxo remoto: depois do answer, BYE do chamador recebe 200 e encerra as duas abas.
    $remoteCallId = 'remote-hangup-' . bin2hex(random_bytes(6));
    $caller->sendto('127.0.0.1', 4000, sipPacket('INVITE', $user, $domain, $callerPort, $remoteCallId, 'z9hG4bK-remote-hangup', $sdp));
    if (!receiveSip($caller, fn(string $packet) => str_starts_with($packet, 'SIP/2.0 180'))) {
        throw new RuntimeException('chamada de teste do BYE remoto não tocou');
    }
    if (!receiveWs($tab1, fn(array $m) => ($m['type'] ?? '') === 'incomingCall' && ($m['data']['callId'] ?? '') === $remoteCallId)
        || !receiveWs($tab2, fn(array $m) => ($m['type'] ?? '') === 'incomingCall' && ($m['data']['callId'] ?? '') === $remoteCallId)) {
        throw new RuntimeException('chamada de teste do BYE remoto não chegou às duas abas');
    }
    $tab1->push(json_encode(['id' => 'accept-remote', 'type' => 'callAccept', 'data' => ['fp' => $fingerprint, 'callId' => $remoteCallId]]));
    if (!receiveSip($caller, fn(string $packet) => str_starts_with($packet, 'SIP/2.0 200'))) {
        throw new RuntimeException('answer do teste do BYE remoto não gerou 200 OK');
    }
    $caller->sendto('127.0.0.1', 4000, sipPacket('BYE', $user, $domain, $callerPort, $remoteCallId, 'z9hG4bK-remote-bye'));
    $remoteByeOk = receiveSip($caller, fn(string $packet) => str_starts_with($packet, 'SIP/2.0 200'));
    $remoteEnded1 = receiveWs($tab1, fn(array $m) => ($m['type'] ?? '') === 'event' && ($m['data'] ?? '') === 'bye');
    $remoteEnded2 = receiveWs($tab2, fn(array $m) => ($m['type'] ?? '') === 'event' && ($m['data'] ?? '') === 'bye');
    if (!$remoteByeOk || !$remoteEnded1 || !$remoteEnded2) {
        throw new RuntimeException('BYE remoto não recebeu 200 ou não encerrou as duas abas');
    }

    $caller->close();
    $tab1->close();
    $tab2->close();
    echo json_encode([
        'inbound_ringing' => true,
        'answer_200_ok' => true,
        'local_bye' => true,
        'bye_200_ok_sent' => true,
        'hangup_success' => true,
        'callEnded_tabs' => 2,
        'next_inbound_ringing' => true,
        'reject_486_busy' => true,
        'remote_bye_200_ok' => true,
        'remote_bye_tabs' => 2,
    ], JSON_PRETTY_PRINT) . PHP_EOL;
    $exitCode = 0;
});
exit($exitCode);
