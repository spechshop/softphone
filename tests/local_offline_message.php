<?php

use Swoole\Coroutine;
use Swoole\Coroutine\Socket;

$exitCode = 1;
Coroutine\run(function () use (&$exitCode): void {
    $socket = new Socket(AF_INET, SOCK_DGRAM, SOL_UDP);
    $socket->bind('127.0.0.1', 0);
    $local = $socket->getsockname();
    $callId = 'controlled-message-' . bin2hex(random_bytes(6));
    $body = 'offline-message-regression-' . bin2hex(random_bytes(3));
    $packet = "MESSAGE sip:spechphone@147.93.67.151:4000 SIP/2.0\r\n"
        . "Via: SIP/2.0/UDP 127.0.0.1:{$local['port']};branch=z9hG4bK-offline-message;rport\r\n"
        . "From: <sip:controlled-sender@remote.test>;tag=offline-message\r\n"
        . "To: <sip:spechphone@147.93.67.151>\r\n"
        . "Call-ID: {$callId}\r\n"
        . "CSeq: 1 MESSAGE\r\n"
        . "Max-Forwards: 70\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . 'Content-Length: ' . strlen($body) . "\r\n\r\n{$body}";
    $socket->sendto('127.0.0.1', 4000, $packet);

    $peer = [];
    $response = $socket->recvfrom($peer, 3.0);
    $socket->close();
    $code = is_string($response) && preg_match('#^SIP/2.0\s+(\d{3})#', $response, $match)
        ? (int)$match[1] : 0;
    $passed = $code === 200;
    echo json_encode(['message_received_on' => 4000, 'response_code' => $code, 'passed' => $passed]) . PHP_EOL;
    $exitCode = $passed ? 0 : 1;
});
exit($exitCode);
