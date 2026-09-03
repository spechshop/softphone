<?php

require __DIR__ . '/../libspech/plugins/autoloader.php';
foreach ([
    'OpusConfig.php', 'SipRegisterManager.php', 'SdpHelper.php', 'SipTransactionManager.php', 'SipDialog.php',
    'SipDigestAuth.php', 'PhoneController.php', 'OutboundMediaSession.php', 'OutboundCall.php',
] as $helper) require_once __DIR__ . '/../plugins/Utils/helpers/' . $helper;

use helpers\utils\OutboundCall;
use helpers\utils\PhoneController;
use helpers\utils\SipRegisterManager;
use libspech\Sip\sip;
use Swoole\Coroutine;

function outboundAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function outboundSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
}

function outboundAccount(string $user = 'alice', string $domain = 'spechshop.test'): array
{
    return ['sipServer' => '127.0.0.1:5060', 'sipDomain' => $domain, 'sipUser' => $user, 'sipPass' => 'fixture-only-secret'];
}

final class OutboundProvider
{
    public array $packets = [];
    public int $sourcePort = SipRegisterManager::SIP_PORT;
    public string $mode;
    public int $finalCode;
    private array $challenged = [];
    private array $cancelled = [];

    public function __construct(string $mode = 'auth401', int $finalCode = 200)
    {
        $this->mode = $mode;
        $this->finalCode = $finalCode;
    }

    public function sendto(string $ip, int $port, string $packet): int|false
    {
        $request = sip::parse($packet);
        $this->packets[] = ['ip' => $ip, 'port' => $port, 'raw' => $packet, 'message' => $request];
        $method = strtoupper((string)$request['method']);
        if ($this->mode === 'timeout') return strlen($packet);

        if ($method === 'INVITE') {
            $cseq = (int)$request['headers']['CSeq'][0];
            $hasAuth = isset($request['headers']['Authorization']) || isset($request['headers']['Proxy-Authorization']);
            go(function () use ($request, $cseq, $hasAuth): void {
                Coroutine::sleep(0.002);
                if ($this->mode === 'wrong-call-first') {
                    $wrong = $this->response($request, 180, ['Call-ID' => ['unrelated']]);
                    PhoneController::instance()->handlePacket(sip::parse($wrong), ['address' => '127.0.0.1', 'port' => 5060]);
                    $this->mode = 'plain';
                }
                if (($this->mode === 'auth401' || $this->mode === 'auth407') && !$hasAuth && !isset($this->challenged[$cseq])) {
                    $this->challenged[$cseq] = true;
                    $code = $this->mode === 'auth407' ? 407 : 401;
                    $this->deliver($this->response($request, $code));
                    return;
                }
                if ($this->mode === 'failure') {
                    $this->deliver($this->response($request, $this->finalCode));
                    return;
                }
                if ($this->mode === 'cancel' || $this->mode === 'cancel-race' || $this->mode === 'provisional-timeout') {
                    $this->deliver($this->response($request, 180));
                    return;
                }
                $this->deliver($this->response($request, 100));
                $this->deliver($this->response($request, 180));
                $this->deliver($this->response($request, 180));
                $this->deliver($this->response($request, 183, [], true));
                $this->deliver($this->response($request, $this->finalCode, [], $this->finalCode === 200));
                if ($this->mode === 'duplicate200' && $this->finalCode === 200) {
                    $this->deliver($this->response($request, 200, [], true));
                }
            });
        } elseif ($method === 'CANCEL') {
            $cseq = (int)$request['headers']['CSeq'][0];
            if (!isset($this->cancelled[$cseq])) {
                $this->cancelled[$cseq] = true;
                go(function () use ($request): void {
                    Coroutine::sleep(0.002);
                    $this->deliver($this->response($request, 200));
                    $invite = $this->latest('INVITE');
                    if ($this->mode === 'cancel-race') $this->deliver($this->response($invite, 200, [], true));
                    else $this->deliver($this->response($invite, 487));
                });
            }
        } elseif ($method === 'BYE') {
            go(function () use ($request): void {
                Coroutine::sleep(0.002);
                $this->deliver($this->response($request, 200));
            });
        }
        return strlen($packet);
    }

    private function latest(string $method): array
    {
        for ($i = count($this->packets) - 1; $i >= 0; $i--) {
            if ($this->packets[$i]['message']['method'] === $method) return $this->packets[$i]['message'];
        }
        throw new RuntimeException("missing {$method}");
    }

    private function deliver(string $packet): void
    {
        PhoneController::instance()->handlePacket(sip::parse($packet), ['address' => '127.0.0.1', 'port' => 5060]);
    }

    private function response(array $request, int $code, array $override = [], bool $sdp = false): string
    {
        $reason = [100 => 'Trying', 180 => 'Ringing', 183 => 'Session Progress', 200 => 'OK', 401 => 'Unauthorized',
            403 => 'Forbidden', 404 => 'Not Found', 407 => 'Proxy Authentication Required', 408 => 'Request Timeout',
            480 => 'Temporarily Unavailable', 486 => 'Busy Here', 487 => 'Request Terminated', 488 => 'Not Acceptable Here',
            500 => 'Server Internal Error', 503 => 'Service Unavailable'][$code] ?? 'Response';
        $headers = [
            'Via' => [$request['headers']['Via'][0]], 'From' => [$request['headers']['From'][0]],
            'To' => [$request['headers']['To'][0] . (str_contains($request['headers']['To'][0], ';tag=') ? '' : ';tag=remote-tag')],
            'Call-ID' => [$request['headers']['Call-ID'][0]], 'CSeq' => [$request['headers']['CSeq'][0]],
        ];
        if ($code === 401) $headers['WWW-Authenticate'] = ['Digest realm="spechshop.test", nonce="unit-nonce", algorithm=MD5, qop="auth"'];
        if ($code === 407) $headers['Proxy-Authenticate'] = ['Digest realm="spechshop.test", nonce="proxy-nonce", algorithm=MD5, qop="auth"'];
        if ($code === 200 && str_ends_with($request['headers']['CSeq'][0], 'INVITE')) {
            $headers['Contact'] = ['<sip:callee@127.0.0.1:5090>'];
            $headers['Record-Route'] = ['<sip:127.0.0.1:5070;lr>', '<sip:127.0.0.1:5080;lr>'];
        }
        $headers = array_replace($headers, $override);
        $body = $sdp ? "v=0\r\no=- 1 1 IN IP4 127.0.0.1\r\ns=test\r\nc=IN IP4 127.0.0.1\r\nt=0 0\r\nm=audio 32000 RTP/AVP 8 101\r\na=rtpmap:8 PCMA/8000\r\na=rtpmap:101 telephone-event/8000\r\na=fmtp:101 0-15\r\n" : '';
        $raw = "SIP/2.0 {$code} {$reason}\r\n";
        foreach ($headers as $name => $values) foreach ($values as $value) $raw .= "{$name}: {$value}\r\n";
        if ($body !== '') $raw .= "Content-Type: application/sdp\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}";
        else $raw .= "Content-Length: 0\r\n\r\n";
        return $raw;
    }
}

final class ConcurrentRegisterProvider
{
    public int $sourcePort = 4000;
    public array $packets = [];
    public function sendto(string $ip, int $port, string $packet): int|false
    {
        $request = sip::parse($packet);
        $this->packets[] = $request;
        go(function () use ($request): void {
            Coroutine::sleep(0.003);
            $authorized = isset($request['headers']['Authorization']);
            $code = $authorized ? 200 : 401;
            $headers = [
                'Via' => [preg_replace('/;rport/i', ';received=198.51.100.20;rport=4000', $request['headers']['Via'][0])],
                'From' => $request['headers']['From'], 'To' => [$request['headers']['To'][0] . ';tag=registrar'],
                'Call-ID' => $request['headers']['Call-ID'], 'CSeq' => $request['headers']['CSeq'],
            ];
            if ($code === 401) $headers['WWW-Authenticate'] = ['Digest realm="spechshop.test", nonce="register-nonce", algorithm=MD5, qop="auth"'];
            else $headers['Contact'] = [$request['headers']['Contact'][0] . ';expires=1800'];
            $raw = "SIP/2.0 {$code} " . ($code === 200 ? 'OK' : 'Unauthorized') . "\r\n";
            foreach ($headers as $name => $values) foreach ($values as $value) $raw .= "{$name}: {$value}\r\n";
            $raw .= "Content-Length: 0\r\n\r\n";
            SipRegisterManager::handleResponse(sip::parse($raw), ['address' => '127.0.0.1', 'port' => 5060]);
        });
        return strlen($packet);
    }
}

function runOutbound(OutboundProvider $provider, array $account, ?callable $configure = null, array $options = []): array
{
    $controller = PhoneController::resetForTests($provider);
    $call = $controller->createOutboundCall($account, '551199999999', $options + [
        'noResponseTimeout' => 0.08, 'provisionalTimeout' => 0.15,
    ]);
    if ($configure) $configure($call);
    $result = $call->start();
    return [$result, $call, $controller];
}

Coroutine\run(function (): void {
    foreach (['auth401', 'auth407'] as $mode) {
        $provider = new OutboundProvider($mode);
        $answerResponse = null;
        [$result, $call, $controller] = runOutbound($provider, outboundAccount(), static function (OutboundCall $call) use (&$answerResponse): void {
            $call->onAnswer(static function (OutboundCall $call, array $response) use (&$answerResponse): void {
                $answerResponse = $response;
                $call->hangup();
            });
        });
        outboundAssert($result, "{$mode} deve estabelecer e encerrar");
        outboundSame($call->callId, $answerResponse['headers']['Call-ID'][0] ?? null, 'callback de atendimento deve receber Call-ID');
        outboundSame($provider->packets[0]['message']['headers']['From'][0], $answerResponse['headers']['From'][0] ?? null,
            'callback de atendimento deve receber From');
        $methods = array_column(array_column($provider->packets, 'message'), 'method');
        outboundAssert(in_array('ACK', $methods, true), 'challenge e 200 devem receber ACK');
        outboundAssert(in_array('BYE', $methods, true), 'hangup local deve gerar BYE');
        $invites = array_values(array_filter($provider->packets, fn($p) => $p['message']['method'] === 'INVITE'));
        outboundSame(2, count($invites), 'challenge deve gerar exatamente duas client transactions INVITE');
        $branch1 = sip::extractVia($invites[0]['message']['headers']['Via'][0])['branch'];
        $branch2 = sip::extractVia($invites[1]['message']['headers']['Via'][0])['branch'];
        outboundAssert($branch1 !== $branch2, 'INVITE autenticado deve usar branch novo');
        outboundSame($invites[0]['message']['headers']['Call-ID'][0], $invites[1]['message']['headers']['Call-ID'][0], 'Call-ID deve permanecer');
        outboundSame($invites[0]['message']['headers']['From'][0], $invites[1]['message']['headers']['From'][0], 'From tag deve permanecer');
        outboundAssert(!str_contains($invites[1]['message']['headers']['To'][0], 'remote-tag'), 'novo INVITE não pode herdar To tag do challenge');
        $authHeader = $mode === 'auth407' ? 'Proxy-Authorization' : 'Authorization';
        outboundAssert(isset($invites[1]['message']['headers'][$authHeader]), "{$authHeader} deve existir");
        foreach ($provider->packets as $sent) {
            $method = $sent['message']['method'];
            if (in_array($method, ['INVITE','ACK','CANCEL','BYE'], true)) {
                outboundSame(4000, $provider->sourcePort, "{$method} deve usar transporte :4000");
                outboundAssert(str_contains($sent['message']['headers']['Via'][0], ':4000'), "Via de {$method} deve anunciar :4000");
            }
        }
        outboundSame('sip:callee@127.0.0.1:5090', $call->dialog->remoteTarget, 'Contact deve atualizar remote target');
        outboundSame(['<sip:127.0.0.1:5080;lr>', '<sip:127.0.0.1:5070;lr>'], $call->dialog->routeSet, 'Record-Route deve ser invertido no UAC');
        $bye = array_values(array_filter($provider->packets, fn($p) => $p['message']['method'] === 'BYE'))[0];
        outboundSame(5080, $bye['port'], 'primeiro Route deve definir next hop');
        outboundSame(0, $controller->activeCallCount(), 'cleanup deve remover call');
        outboundSame(0, $controller->activeDialogCount(), 'cleanup deve remover dialog');
        outboundSame(0, $controller->pendingTransactionCount(), 'cleanup deve remover transactions');
    }

    $cancelProvider = new OutboundProvider('cancel');
    [$cancelResult, , $cancelController] = runOutbound($cancelProvider, outboundAccount(), static function (OutboundCall $call): void {
        $call->onRinging(static fn(OutboundCall $call) => $call->hangup());
    });
    $cancelMethods = array_column(array_column($cancelProvider->packets, 'message'), 'method');
    outboundAssert(in_array('CANCEL', $cancelMethods, true), 'early hangup deve enviar CANCEL');
    outboundAssert(in_array('ACK', $cancelMethods, true), '487 INVITE deve receber ACK');
    outboundAssert(!in_array('BYE', $cancelMethods, true), 'CANCEL/487 não deve enviar BYE');
    outboundSame(0, $cancelController->pendingTransactionCount(), 'CANCEL deve limpar transactions');

    $raceProvider = new OutboundProvider('cancel-race');
    [, , $raceController] = runOutbound($raceProvider, outboundAccount(), static function (OutboundCall $call): void {
        $call->onRinging(static fn(OutboundCall $call) => $call->hangup());
    });
    $raceMethods = array_column(array_column($raceProvider->packets, 'message'), 'method');
    outboundAssert(in_array('ACK', $raceMethods, true) && in_array('BYE', $raceMethods, true), 'CANCEL/200 crossing deve gerar ACK e BYE');
    outboundSame(0, $raceController->activeDialogCount(), 'corrida deve limpar dialog');

    $duplicate = new OutboundProvider('duplicate200');
    [, , $duplicateController] = runOutbound($duplicate, outboundAccount(), static function (OutboundCall $call): void {
        $call->onAnswer(static fn(OutboundCall $call) => $call->hangup());
    });
    $duplicateAcks = array_filter($duplicate->packets, static fn(array $p): bool => $p['message']['method'] === 'ACK');
    outboundAssert(count($duplicateAcks) >= 2, '200 INVITE retransmitido deve receber ACK novamente');
    outboundSame(0, $duplicateController->pendingTransactionCount(), '2xx duplicado deve limpar transaction ao final');

    $remoteBye = new OutboundProvider('plain');
    $remoteByeHandled = [];
    [, $remoteByeCall, $remoteByeController] = runOutbound($remoteBye, outboundAccount(),
        static function (OutboundCall $call) use (&$remoteByeHandled): void {
            $call->onAnswer(static function (OutboundCall $call) use (&$remoteByeHandled): void {
                $raw = "BYE sip:alice@127.0.0.1:4000 SIP/2.0\r\n"
                    . "Via: SIP/2.0/UDP 127.0.0.1:5060;branch=z9hG4bK-remote-bye\r\n"
                    . "From: <sip:callee@spechshop.test>;tag={$call->dialog->remoteTag}\r\n"
                    . "To: <sip:alice@spechshop.test>;tag={$call->dialog->localTag}\r\n"
                    . "Call-ID: {$call->callId}\r\nCSeq: 77 BYE\r\nContent-Length: 0\r\n\r\n";
                $message = sip::parse($raw);
                $remoteByeHandled[] = PhoneController::instance()->handlePacket($message, ['address' => '127.0.0.1', 'port' => 5060]);
                $remoteByeHandled[] = PhoneController::instance()->handlePacket($message, ['address' => '127.0.0.1', 'port' => 5060]);
            });
        });
    outboundSame([true, true], $remoteByeHandled, 'BYE remoto e retransmissão devem ser consumidos idempotentemente');
    $byeResponses = array_filter($remoteBye->packets, static fn(array $p): bool =>
        $p['message']['method'] === '200' && str_ends_with($p['message']['headers']['CSeq'][0] ?? '', 'BYE'));
    outboundSame(2, count($byeResponses), 'BYE remoto duplicado deve receber 200 duas vezes');
    outboundSame(0, $remoteByeController->activeDialogCount(), 'BYE remoto deve limpar dialog');

    foreach ([403,404,408,480,486,488,500,503] as $code) {
        $provider = new OutboundProvider('failure', $code);
        $seen = null;
        [, , $controller] = runOutbound($provider, outboundAccount(), static function (OutboundCall $call) use (&$seen): void {
            $call->onFailed(static function (OutboundCall $call, string $reason, int $code) use (&$seen): void { $seen = $code; });
        });
        outboundSame($code, $seen, "falha {$code} deve ser propagada");
        outboundSame(0, $controller->pendingTransactionCount(), "falha {$code} deve limpar transaction");
    }

    $timeout = new OutboundProvider('timeout');
    $timeoutCode = null;
    [, , $timeoutController] = runOutbound($timeout, outboundAccount(), static function (OutboundCall $call) use (&$timeoutCode): void {
        $call->onFailed(static function (OutboundCall $call, string $reason, int $code) use (&$timeoutCode): void { $timeoutCode = $code; });
    }, ['noResponseTimeout' => 0.65]);
    outboundSame(408, $timeoutCode, 'timeout sem resposta deve ser 408');
    outboundAssert(count($timeout->packets) >= 2, 'timeout UDP deve retransmitir INVITE com a mesma transação');
    $timeoutBranches = array_unique(array_map(static fn(array $p): string => sip::extractVia($p['message']['headers']['Via'][0])['branch'], $timeout->packets));
    outboundSame(1, count($timeoutBranches), 'retransmissão deve preservar Via branch');
    outboundSame(0, $timeoutController->pendingTransactionCount(), 'timeout deve limpar transaction');

    $provisional = new OutboundProvider('provisional-timeout');
    $provisionalReason = null;
    [, , $provisionalController] = runOutbound($provisional, outboundAccount(),
        static function (OutboundCall $call) use (&$provisionalReason): void {
            $call->onFailed(static function (OutboundCall $call, string $reason) use (&$provisionalReason): void { $provisionalReason = $reason; });
        }, ['provisionalTimeout' => 0.03]);
    outboundSame('timeout_after_provisional', $provisionalReason, 'timeout após provisional deve ser distinto');
    outboundSame(0, $provisionalController->pendingTransactionCount(), 'timeout provisional deve limpar transaction');

    // REGISTER and renewal while an outbound INVITE transaction is active.
    $callDuringRegister = new OutboundProvider('provisional-timeout');
    $concurrentController = PhoneController::resetForTests($callDuringRegister);
    $concurrentCall = $concurrentController->createOutboundCall(outboundAccount(), '3000',
        ['noResponseTimeout' => 0.2, 'provisionalTimeout' => 0.08]);
    $callDone = false;
    go(function () use ($concurrentCall, &$callDone): void { $concurrentCall->start(); $callDone = true; });
    Coroutine::sleep(0.01);
    $registerProvider = new ConcurrentRegisterProvider();
    $registration = SipRegisterManager::register($registerProvider, outboundAccount(), 1800, 0.2);
    $renewal = SipRegisterManager::register($registerProvider, outboundAccount(), 1800, 0.2);
    while (!$callDone) Coroutine::sleep(0.005);
    outboundAssert($registration['success'] && $renewal['success'], 'REGISTER e renovação durante chamada devem concluir');
    outboundSame(4000, $registration['source_port'], 'REGISTER concorrente deve manter :4000');
    outboundSame(0, SipRegisterManager::pendingCount(), 'REGISTER concorrente deve limpar pending');
    outboundSame(0, $concurrentController->pendingTransactionCount(), 'chamada concorrente deve limpar transaction');

    $wrong = new OutboundProvider('wrong-call-first', 403);
    [, , $wrongController] = runOutbound($wrong, outboundAccount());
    outboundSame(0, $wrongController->pendingTransactionCount(), 'Call-ID alheio não deve afetar cleanup');

    outboundAssert(PhoneController::accountKey(outboundAccount('same', 'one.test'))
        !== PhoneController::accountKey(outboundAccount('same', 'two.test')), 'mesmo username em domínios distintos deve ter accountKey distinto');

    // 50 interleaved dialogs, including ten calls from the same account.
    $controllers = [];
    $done = 0;
    $shared = new OutboundProvider('failure', 486);
    $controller = PhoneController::resetForTests($shared);
    for ($i = 0; $i < 50; $i++) {
        $account = outboundAccount($i < 10 ? 'shared' : 'user' . $i, 'domain' . ($i % 10) . '.test');
        $call = $controller->createOutboundCall($account, '100' . $i, ['noResponseTimeout' => 0.1, 'provisionalTimeout' => 0.1]);
        go(function () use ($call, &$done): void { $call->start(); $done++; });
    }
    while ($done < 50) Coroutine::sleep(0.005);
    outboundSame(0, $controller->activeCallCount(), '50 dialogs devem finalizar');
    outboundSame(0, $controller->pendingTransactionCount(), '50 dialogs não podem deixar pending');

    $fdBaseline = count(scandir('/proc/self/fd'));
    $coroutineBaseline = (int)(Coroutine::stats()['coroutine_num'] ?? 1);
    $stress = new OutboundProvider('failure', 486);
    $stressController = PhoneController::resetForTests($stress);
    for ($i = 0; $i < 100; $i++) {
        $call = $stressController->createOutboundCall(outboundAccount('stress'), '200' . $i,
            ['noResponseTimeout' => 0.1, 'provisionalTimeout' => 0.1]);
        $call->start();
    }
    unset($call);
    Coroutine::sleep(0.3);
    gc_collect_cycles();
    outboundSame(0, $stressController->activeCallCount(), '100 chamadas sequenciais devem limpar calls');
    outboundSame(0, $stressController->pendingTransactionCount(), '100 chamadas sequenciais devem limpar transactions');
    outboundAssert(count(scandir('/proc/self/fd')) <= $fdBaseline + 2, 'RTP sockets devem retornar ao baseline');
    outboundAssert((int)(Coroutine::stats()['coroutine_num'] ?? 1) <= $coroutineBaseline + 1, 'coroutines devem retornar ao baseline');
});

foreach (['OutboundCall.php', 'PhoneController.php', 'SipTransactionManager.php'] as $file) {
    $source = file_get_contents(__DIR__ . '/../plugins/Utils/helpers/' . $file);
    outboundAssert(!str_contains($source, 'recvfrom('), "{$file} não pode ler UDP SIP");
    outboundAssert(!preg_match('/new\s+(?:\\\\?Swoole\\\\Coroutine\\\\)?(?:Client|Socket)\b/', $source), "{$file} não pode criar socket SIP");
}
$messageSource = file_get_contents(__DIR__ . '/../plugins/Message/handlers/messageSend.php');
outboundAssert(!str_contains($messageSource, 'new \\Swoole\\Coroutine\\Client'), 'MESSAGE não pode criar socket UDP efêmero');
outboundAssert(str_contains($messageSource, 'PhoneController::instance($socket)->send'), 'MESSAGE deve usar transporte principal');
$startSource = file_get_contents(__DIR__ . '/../plugins/Message/handlers/startCall.php');
outboundAssert(!str_contains($startSource, 'trunkController'), 'startCall não pode depender de trunkController');
outboundAssert(!preg_match('/->(?:register|call|bye)\s*\(/', $startSource), 'startCall não pode usar register/call/bye legado');

echo "OK: outbound :4000, 401/407, ACK, provisional, route-set, BYE, CANCEL/race, falhas, timeout, concorrência e cleanup.\n";
