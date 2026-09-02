<?php

use Swoole\Coroutine;
use Swoole\Coroutine\Socket;

$options = getopt('', ['timeout', 'cancel-after::']);
$timeoutMode = array_key_exists('timeout', $options);
$cancelAfter = max(1, (int)($options['cancel-after'] ?? 3));
$exitCode = 1;
Coroutine\run(function () use (&$exitCode, $timeoutMode, $cancelAfter): void {
    $socket = new Socket(AF_INET, SOCK_DGRAM, SOL_UDP);
    $socket->bind('127.0.0.1', 0);
    $local = $socket->getsockname();
    $branch = 'z9hG4bK-controlled-inbound';
    $callId = 'controlled-inbound-' . bin2hex(random_bytes(6));
    $headers = [
        "Via: SIP/2.0/UDP 127.0.0.1:{$local['port']};branch={$branch};rport",
        'From: <sip:controlled-caller@spechshop.com>;tag=controlled',
        'To: <sip:spechphone@147.93.67.151>',
        'Call-ID: ' . $callId,
        'Contact: <sip:controlled-caller@127.0.0.1:' . $local['port'] . '>',
        'Max-Forwards: 70',
    ];
    $sdp = "v=0\r\no=controlled 1 1 IN IP4 127.0.0.1\r\ns=controlled\r\nc=IN IP4 127.0.0.1\r\nt=0 0\r\nm=audio 50000 RTP/AVP 8\r\na=rtpmap:8 PCMA/8000\r\n";
    $invite = "INVITE sip:spechphone@147.93.67.151:4000 SIP/2.0\r\n"
        . implode("\r\n", [...$headers, 'CSeq: 1 INVITE', 'Content-Type: application/sdp', 'Content-Length: ' . strlen($sdp)])
        . "\r\n\r\n{$sdp}";
    $socket->sendto('127.0.0.1', 4000, $invite);

    $codes = [];
    $deadline = microtime(true) + ($timeoutMode ? 35 : $cancelAfter);
    while (microtime(true) < $deadline) {
        $peer = [];
        $response = $socket->recvfrom($peer, 0.4);
        if (!is_string($response)) continue;
        if (preg_match('#^SIP/2.0\s+(\d{3})#', $response, $match)) $codes[] = (int)$match[1];
        if (in_array(180, $codes, true) || in_array(480, $codes, true)) break;
    }

    if (!$timeoutMode) {
        $cancel = "CANCEL sip:spechphone@147.93.67.151:4000 SIP/2.0\r\n"
            . implode("\r\n", [...$headers, 'CSeq: 1 CANCEL', 'Content-Length: 0'])
            . "\r\n\r\n";
        $socket->sendto('127.0.0.1', 4000, $cancel);
        $cleanupDeadline = microtime(true) + 2;
        while (microtime(true) < $cleanupDeadline) {
            $peer = [];
            $response = $socket->recvfrom($peer, 0.3);
            if (!is_string($response)) continue;
            if (preg_match('#^SIP/2.0\s+(\d{3})#', $response, $match)) $codes[] = (int)$match[1];
            if (in_array(487, $codes, true)) break;
        }
    }
    $socket->close();
    // With a browser open the branch sends 180 immediately. With every
    // browser closed it keeps the call pending for push/reconnect; CANCEL then
    // proves the same inbound transaction was retained and finalized with 487.
    $passed = in_array(100, $codes, true) && ($timeoutMode
        ? in_array(480, $codes, true)
        : (in_array(180, $codes, true) || in_array(487, $codes, true)));
    echo json_encode(['inbound_received_on' => 4000, 'mode' => $timeoutMode ? 'timeout' : 'cancel',
        'response_codes' => array_values(array_unique($codes)), 'passed' => $passed]) . PHP_EOL;
    $exitCode = $passed ? 0 : 1;
});
exit($exitCode);
