<?php

require __DIR__ . '/../plugins/Utils/helpers/SipMessageBody.php';

use helpers\utils\SipMessageBody;

function bodyExpect(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) throw new RuntimeException("{$message}: expected=" . var_export($expected, true) . ' actual=' . var_export($actual, true));
}

$packet = "MESSAGE sip:spechphone@100.68.32.189:4000 SIP/2.0\r\n"
    . "Via: SIP/2.0/UDP 147.93.67.151:5060;branch=z9hG4bK-test;rport\r\n"
    . "From: <sip:lottoka@spechshop.com>;tag=test\r\n"
    . "To: <sip:spechphone@spechshop.com>\r\n"
    . "Call-ID: test-message\r\nCSeq: 1 MESSAGE\r\n"
    . "Content-Length: 5\r\nContent-Type: text/plain;charset=UTF-8\r\n\r\n"
    . 'teste';

bodyExpect('teste', SipMessageBody::extract($packet), 'Content-Type com charset perdeu body');
bodyExpect('teste', SipMessageBody::extract($packet, ['headers' => []]), 'fallback do parser não extraiu body');
$shortPacket = str_replace(["Content-Length: 5", 'teste'], ["Content-Length: 3", 'foo-extra'], $packet);
bodyExpect('foo', SipMessageBody::extract($shortPacket), 'Content-Length não foi respeitado');
bodyExpect('parsed', SipMessageBody::extract($packet, ['body' => 'parsed']), 'body já parseado deveria ter prioridade');
bodyExpect('teste', SipMessageBody::extract(str_replace("\r\n", "\n", $packet)), 'pacote LF não foi aceito');

echo "OK: SIP MESSAGE extrai body com parâmetros de Content-Type e respeita Content-Length.\n";
