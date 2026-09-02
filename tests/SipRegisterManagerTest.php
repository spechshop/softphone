<?php

require __DIR__ . '/../libspech/plugins/autoloader.php';
require __DIR__ . '/../plugins/Utils/helpers/SipRegisterManager.php';

use helpers\utils\SipRegisterManager;
use libspech\Sip\sip;
use Swoole\Coroutine;

final class FakeSipProvider
{
    public array $packets = [];
    public int $sourcePort = SipRegisterManager::SIP_PORT;
    public string $mode;
    public float $delay;

    public function __construct(string $mode = 'valid', float $delay = 0.001)
    {
        $this->mode = $mode;
        $this->delay = $delay;
    }

    public function sendto(string $ip, int $port, string $packet): int|false
    {
        $parsed = sip::parse($packet);
        $this->packets[] = ['ip' => $ip, 'port' => $port, 'raw' => $packet, 'message' => $parsed];
        if ($this->mode === 'timeout') return strlen($packet);

        go(function () use ($parsed): void {
            Coroutine::sleep($this->delay);
            $hasAuthorization = isset($parsed['headers']['Authorization']);
            $hasProxyAuthorization = isset($parsed['headers']['Proxy-Authorization']);

            if ($this->mode === 'wrong-correlation') {
                $wrong = $this->response($parsed, 200, ['Call-ID' => ['unrelated-call-id']]);
                SipRegisterManager::handleResponse(sip::parse($wrong), ['address' => '127.0.0.1', 'port' => 5060]);
            }

            if ($this->mode === 'unexpected') {
                $code = 500;
            } elseif ($this->mode === 'forbidden' && $hasAuthorization) {
                $code = 403;
            } elseif ($this->mode === 'proxy') {
                $code = $hasProxyAuthorization ? 200 : 407;
            } elseif ($this->mode === 'invalid') {
                $code = 401;
            } else {
                $code = $hasAuthorization ? 200 : 401;
            }

            $raw = $this->response($parsed, $code);
            SipRegisterManager::handleResponse(sip::parse($raw), ['address' => '127.0.0.1', 'port' => 5060]);
        });
        return strlen($packet);
    }

    private function response(array $request, int $code, array $override = []): string
    {
        $reason = [200 => 'OK', 401 => 'Unauthorized', 403 => 'Forbidden', 407 => 'Proxy Authentication Required', 500 => 'Server Error'][$code];
        $via = $request['headers']['Via'][0];
        $via = preg_replace('/;rport(?:=\d+)?/i', ';received=198.51.100.10;rport=4000', $via);
        $headers = [
            'Via' => [$via],
            'From' => [$request['headers']['From'][0]],
            'To' => [$request['headers']['To'][0] . ';tag=provider'],
            'Call-ID' => [$request['headers']['Call-ID'][0]],
            'CSeq' => [$request['headers']['CSeq'][0]],
        ];
        if ($code === 401) {
            $headers['WWW-Authenticate'] = ['Digest realm="spechshop.test", nonce="test-nonce", algorithm=MD5, qop="auth"'];
        } elseif ($code === 407) {
            $headers['Proxy-Authenticate'] = ['Digest realm="spechshop.test", nonce="proxy-nonce", algorithm=MD5, qop="auth"'];
        } elseif ($code === 200) {
            $headers['Contact'] = [$request['headers']['Contact'][0] . ';expires=1800'];
            $headers['Expires'] = ['1800'];
        }
        $headers = array_replace($headers, $override);

        $raw = "SIP/2.0 {$code} {$reason}\r\n";
        foreach ($headers as $name => $values) {
            foreach ($values as $value) $raw .= "{$name}: {$value}\r\n";
        }
        return $raw . "Content-Length: 0\r\n\r\n";
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function account(string $user = '1000'): array
{
    return [
        'sipServer' => '127.0.0.1:5060',
        'sipDomain' => 'spechshop.test',
        'sipUser' => $user,
        'sipPass' => 'test-only-password',
    ];
}

function runRegistration(FakeSipProvider $provider, ?array $account = null, float $timeout = 1.0): array
{
    return SipRegisterManager::register($provider, $account ?? account(), 1800, $timeout);
}

SipRegisterManager::setLocalIpResolverForTests(static fn(): string => '10.0.0.25');

Coroutine\run(function (): void {
    $provider = new FakeSipProvider('valid');
    $result = runRegistration($provider);
    assertTrue($result['success'], 'REGISTER válido deve concluir');
    assertSameValue(2, count($provider->packets), 'deve enviar REGISTER inicial e autenticado');
    assertSameValue(4000, $provider->sourcePort, 'transporte deve representar listener :4000');
    assertTrue(!isset($provider->packets[0]['message']['headers']['Authorization']), 'primeiro REGISTER não deve ter Authorization');
    assertTrue(isset($provider->packets[1]['message']['headers']['Authorization']), 'segundo REGISTER deve ter Authorization');
    assertSameValue(
        (int)$provider->packets[0]['message']['headers']['CSeq'][0] + 1,
        (int)$provider->packets[1]['message']['headers']['CSeq'][0],
        'REGISTER autenticado deve incrementar CSeq'
    );
    $branch1 = sip::extractVia($provider->packets[0]['message']['headers']['Via'][0])['branch'];
    $branch2 = sip::extractVia($provider->packets[1]['message']['headers']['Via'][0])['branch'];
    assertTrue($branch1 !== $branch2, 'REGISTER autenticado deve usar branch novo');
    assertTrue(str_contains($provider->packets[0]['raw'], ':4000'), 'REGISTER inicial deve anunciar :4000');
    assertTrue(str_contains($provider->packets[1]['message']['headers']['Contact'][0], '@198.51.100.10:4000'), 'Contact autenticado deve usar received e :4000');
    assertTrue(str_contains($provider->packets[1]['message']['headers']['Via'][0], '10.0.0.25:4000'), 'Via deve refletir o sent-by local :4000 e usar rport para NAT');
    assertTrue($result['binding_confirmed'], '200 deve confirmar Contact :4000');
    assertSameValue(0, SipRegisterManager::pendingCount(), 'transação deve limpar pending após sucesso');

    $renewal = new FakeSipProvider('valid');
    $renewalResult = runRegistration($renewal);
    assertTrue($renewalResult['success'], 'renovação deve concluir pelo mesmo fluxo');
    assertSameValue(
        $provider->packets[1]['message']['headers']['Contact'][0],
        $renewal->packets[1]['message']['headers']['Contact'][0],
        'renovação deve manter a identidade do Contact :4000'
    );

    $invalid = new FakeSipProvider('invalid');
    $invalidResult = runRegistration($invalid);
    assertTrue(!$invalidResult['success'], 'credencial inválida não pode registrar');
    assertSameValue('authentication_failed', $invalidResult['reason'], 'segundo 401 deve ser erro de autenticação');
    assertSameValue(2, count($invalid->packets), 'credencial inválida deve fazer challenge e tentativa autenticada');

    $proxy = new FakeSipProvider('proxy');
    $proxyResult = runRegistration($proxy);
    assertTrue($proxyResult['success'], '407 deve ser suportado');
    assertTrue(isset($proxy->packets[1]['message']['headers']['Proxy-Authorization']), '407 deve gerar Proxy-Authorization');

    $forbidden = new FakeSipProvider('forbidden');
    $forbiddenResult = runRegistration($forbidden);
    assertSameValue('authentication_failed', $forbiddenResult['reason'], '403 deve ser falha de autenticação');

    $unexpected = new FakeSipProvider('unexpected');
    $unexpectedResult = runRegistration($unexpected);
    assertSameValue('sip_error', $unexpectedResult['reason'], 'resposta SIP inesperada deve ser erro explícito');
    assertSameValue(500, $unexpectedResult['code'], 'código SIP inesperado deve ser preservado');

    $correlated = new FakeSipProvider('wrong-correlation');
    $correlatedResult = runRegistration($correlated);
    assertTrue($correlatedResult['success'], 'resposta de Call-ID errado não deve roubar a transação correta');

    $timeout = new FakeSipProvider('timeout');
    $timeoutResult = runRegistration($timeout, null, 0.65);
    assertSameValue('timeout', $timeoutResult['reason'], 'ausência de resposta deve expirar');
    assertTrue(count($timeout->packets) >= 2, 'timeout deve retransmitir o mesmo request');
    assertSameValue(
        $timeout->packets[0]['message']['headers']['CSeq'][0],
        $timeout->packets[1]['message']['headers']['CSeq'][0],
        'retransmissão deve manter CSeq'
    );
    assertSameValue(
        sip::extractVia($timeout->packets[0]['message']['headers']['Via'][0])['branch'],
        sip::extractVia($timeout->packets[1]['message']['headers']['Via'][0])['branch'],
        'retransmissão deve manter branch'
    );
    assertSameValue(0, SipRegisterManager::pendingCount(), 'timeout deve limpar pending');

    $slow = new FakeSipProvider('valid', 0.05);
    $concurrent = [];
    go(function () use ($slow, &$concurrent): void { $concurrent[] = runRegistration($slow); });
    Coroutine::sleep(0.005);
    go(function () use ($slow, &$concurrent): void { $concurrent[] = runRegistration($slow); });
    while (count($concurrent) < 2) Coroutine::sleep(0.01);
    $reasons = array_column($concurrent, 'reason');
    sort($reasons);
    assertSameValue(['registered', 'registration_in_progress'], $reasons, 'duas tentativas da mesma conta não podem competir');

    $providerA = new FakeSipProvider('valid', 0.02);
    $providerB = new FakeSipProvider('valid', 0.02);
    $distinct = [];
    go(function () use ($providerA, &$distinct): void { $distinct[] = runRegistration($providerA, account('1001')); });
    go(function () use ($providerB, &$distinct): void { $distinct[] = runRegistration($providerB, account('1002')); });
    while (count($distinct) < 2) Coroutine::sleep(0.01);
    assertTrue($distinct[0]['success'] && $distinct[1]['success'], 'contas distintas devem registrar simultaneamente');

    $sameUserDomainA = new FakeSipProvider('valid', 0.02);
    $sameUserDomainB = new FakeSipProvider('valid', 0.02);
    $sameUserDistinctDomains = [];
    $domainA = account('shared-user'); $domainA['sipDomain'] = 'one.test';
    $domainB = account('shared-user'); $domainB['sipDomain'] = 'two.test';
    go(function () use ($sameUserDomainA, $domainA, &$sameUserDistinctDomains): void {
        $sameUserDistinctDomains[] = runRegistration($sameUserDomainA, $domainA);
    });
    go(function () use ($sameUserDomainB, $domainB, &$sameUserDistinctDomains): void {
        $sameUserDistinctDomains[] = runRegistration($sameUserDomainB, $domainB);
    });
    while (count($sameUserDistinctDomains) < 2) Coroutine::sleep(0.01);
    assertTrue($sameUserDistinctDomains[0]['success'] && $sameUserDistinctDomains[1]['success'],
        'mesmo usuário em domínios distintos deve manter estado de conta separado');

    $tenAccounts = [];
    for ($i = 0; $i < 10; $i++) {
        $provider = new FakeSipProvider('valid', 0.01);
        go(function () use ($provider, $i, &$tenAccounts): void {
            $tenAccounts[] = runRegistration($provider, account('parallel-' . $i));
        });
    }
    while (count($tenAccounts) < 10) Coroutine::sleep(0.01);
    assertSameValue(10, count(array_filter($tenAccounts, static fn(array $result): bool => $result['success'])),
        '10 contas devem registrar simultaneamente pela mesma porta lógica 4000');

    $invite = sip::parse("INVITE sip:1000@198.51.100.10 SIP/2.0\r\nVia: SIP/2.0/UDP 203.0.113.8:5060;branch=z9hG4bK-invite\r\nFrom: <sip:caller@example.test>;tag=a\r\nTo: <sip:1000@example.test>\r\nCall-ID: inbound-call\r\nCSeq: 1 INVITE\r\nContent-Length: 0\r\n\r\n");
    assertTrue(!SipRegisterManager::handleResponse($invite, []), 'INVITE inbound deve continuar para handler global');
    $options = sip::parse("SIP/2.0 200 OK\r\nVia: SIP/2.0/UDP 198.51.100.10:4000;branch=z9hG4bK-options\r\nFrom: <sip:a@example.test>;tag=a\r\nTo: <sip:b@example.test>;tag=b\r\nCall-ID: options-call\r\nCSeq: 1 OPTIONS\r\nContent-Length: 0\r\n\r\n");
    assertTrue(!SipRegisterManager::handleResponse($options, []), 'OPTIONS não pode ser confundido com REGISTER');
});

SipRegisterManager::setLocalIpResolverForTests(null);

foreach ([
    __DIR__ . '/../plugins/Message/handlers/saveConfig.php',
    __DIR__ . '/../plugins/Message/handlers/register.php',
    __DIR__ . '/../plugins/Message/handlers/connect.php',
    __DIR__ . '/../plugins/Utils/helpers/Registrar.php',
] as $registrationCaller) {
    assertTrue(!str_contains(file_get_contents($registrationCaller), 'recvfrom('), basename($registrationCaller) . ' não pode ler socket SIP');
}
assertTrue(
    str_contains(file_get_contents(__DIR__ . '/../server.php'), 'SipRegisterManager::handleResponse($parse, $info)'),
    'listener global :4000 deve despachar respostas REGISTER'
);
assertTrue(
    !str_contains(file_get_contents(__DIR__ . '/../plugins/Message/handlers/startCall.php'), '->register('),
    'chamada outbound não pode sobrescrever o binding com porta efêmera'
);
echo "OK: fluxo REGISTER :4000, autenticação, correlação, retransmissão, concorrência e isolamento inbound.\n";
