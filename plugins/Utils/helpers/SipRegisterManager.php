<?php

namespace helpers\utils;

use libspech\Network\network;
use libspech\Sip\sip;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * Owns client REGISTER transactions that use the SpechPhone UDP listener.
 *
 * This class never creates or reads a SIP socket. The server listener on 4000
 * is the sole reader and dispatches matching responses through handleResponse().
 */
class SipRegisterManager
{
    public const SIP_PORT = 4000;
    public const DEFAULT_REMOTE_PORT = 5060;
    public const DEFAULT_TIMEOUT = 8.0;

    /** @var array<string, array{branch:string, channel:Channel, created_at:float}> */
    private static array $pending = [];

    /** @var array<string, bool> */
    private static array $activeAccounts = [];

    /** @var null|callable */
    private static $localIpResolver = null;

    /**
     * @param object $transport Object exposing sendto(string, int, string).
     * @param array{sipServer:string,sipUser:string,sipPass:string,sipDomain?:string} $account
     * @return array<string, mixed>
     */
    public static function register(
        object $transport,
        array $account,
        int $expires = 1800,
        float $timeout = self::DEFAULT_TIMEOUT
    ): array {
        $startedAt = microtime(true);
        $serverInput = trim((string)($account['sipServer'] ?? ''));
        $username = trim((string)($account['sipUser'] ?? ''));
        $password = (string)($account['sipPass'] ?? '');

        if ($serverInput === '' || $username === '' || $password === '') {
            return self::result(false, 'invalid_configuration', null, $startedAt);
        }
        if (Coroutine::getCid() < 0) {
            return self::result(false, 'coroutine_required', null, $startedAt);
        }

        try {
            $endpoint = self::parseEndpoint($serverInput);
            $remoteIp = network::resolveAddress($endpoint['host'], 4);
            $localIp = self::localIp();
        } catch (\Throwable $exception) {
            return self::result(false, 'host_resolution_failed', null, $startedAt, [
                'detail' => $exception->getMessage(),
            ]);
        }

        $accountKey = hash('sha256', strtolower($endpoint['host']) . ':' . $endpoint['port'] . '|' . $username);
        if (isset(self::$activeAccounts[$accountKey])) {
            return self::result(false, 'registration_in_progress', null, $startedAt);
        }
        self::$activeAccounts[$accountKey] = true;

        try {
            $domain = trim((string)($account['sipDomain'] ?? ''));
            $domain = $domain !== '' ? self::parseEndpoint($domain)['host'] : $endpoint['host'];
            $requestUri = self::sipUri('', $endpoint['host'], $endpoint['port']);
            $callId = bin2hex(random_bytes(16));
            $fromTag = bin2hex(random_bytes(8));
            $cseq = random_int(100, 99999);
            $advertisedIp = self::configuredAdvertisedIp() ?? $localIp;
            $authenticated = false;
            $challengeCount = 0;
            $lastCode = null;
            $lastResponse = null;
            $observedAddress = null;
            $observedPort = null;

            while (true) {
                $branch = 'z9hG4bK-' . bin2hex(random_bytes(12));
                $model = self::buildRegister(
                    $username,
                    $domain,
                    $endpoint['host'],
                    $endpoint['port'],
                    $requestUri,
                    $advertisedIp,
                    $localIp,
                    $callId,
                    $fromTag,
                    $cseq,
                    $branch,
                    $expires
                );

                if ($authenticated && isset($lastResponse)) {
                    $challenge = self::challengeFromResponse($lastResponse);
                    if ($challenge === null) {
                        return self::result(false, 'invalid_challenge', $lastCode, $startedAt);
                    }
                    try {
                        $model['headers'][$challenge['authorization_header']] = [self::digestAuthorization(
                            $username,
                            $password,
                            $requestUri,
                            'REGISTER',
                            $challenge['parameters']
                        )];
                    } catch (\Throwable $exception) {
                        return self::result(false, 'unsupported_challenge', $lastCode, $startedAt, [
                            'detail' => $exception->getMessage(),
                        ]);
                    }
                }

                $packet = sip::renderSolution($model, $advertisedIp);
                $response = self::sendAndWait(
                    $transport,
                    $remoteIp,
                    $endpoint['port'],
                    $packet,
                    $callId,
                    $cseq,
                    $branch,
                    $timeout - (microtime(true) - $startedAt)
                );
                if ($response === null) {
                    return self::result(false, 'timeout', null, $startedAt, [
                        'call_id' => $callId,
                        'cseq' => $cseq,
                    ]);
                }

                $lastResponse = $response['message'];
                $lastCode = (int)$lastResponse['method'];
                [$received, $rport] = self::observedEndpoint($lastResponse);
                if ($received !== null) {
                    $observedAddress = $received;
                    $advertisedIp = $received;
                }
                if ($rport !== null) {
                    $observedPort = $rport;
                }

                if ($lastCode >= 100 && $lastCode < 200) {
                    continue;
                }
                if ($lastCode === 401 || $lastCode === 407) {
                    $challenge = self::challengeFromResponse($lastResponse);
                    if ($challenge === null) {
                        return self::result(false, 'invalid_challenge', $lastCode, $startedAt);
                    }

                    $stale = strtolower((string)($challenge['parameters']['stale'] ?? 'false')) === 'true';
                    if ($authenticated && !$stale) {
                        return self::result(false, 'authentication_failed', $lastCode, $startedAt);
                    }
                    if (++$challengeCount > 2) {
                        return self::result(false, 'authentication_failed', $lastCode, $startedAt);
                    }

                    $authenticated = true;
                    $cseq++;
                    continue;
                }

                if ($lastCode === 200) {
                    $binding = self::bindingFromResponse($lastResponse, $username);
                    return self::result(true, 'registered', 200, $startedAt, [
                        'call_id' => $callId,
                        'cseq' => $cseq,
                        'authenticated' => $authenticated,
                        'contact_host' => $advertisedIp,
                        'contact_port' => self::SIP_PORT,
                        'source_port' => self::SIP_PORT,
                        'observed_address' => $observedAddress,
                        'observed_port' => $observedPort,
                        'nat_port_preserved' => $observedPort === null || $observedPort === self::SIP_PORT,
                        'binding_confirmed' => $binding['confirmed'],
                        'binding_contact' => $binding['contact'],
                        'response' => self::sanitizeResponse($lastResponse),
                    ]);
                }

                if ($lastCode === 403) {
                    return self::result(false, 'authentication_failed', 403, $startedAt);
                }
                if ($lastCode >= 300) {
                    return self::result(false, 'sip_error', $lastCode, $startedAt);
                }

                return self::result(false, 'unexpected_response', $lastCode, $startedAt);
            }
        } catch (\Throwable $exception) {
            return self::result(false, 'internal_error', null, $startedAt, [
                'detail' => $exception->getMessage(),
            ]);
        } finally {
            unset(self::$activeAccounts[$accountKey]);
        }
    }

    /**
     * Called exactly once by the global UDP :4000 packet listener.
     * Returns true only when the packet belongs to a pending REGISTER phase.
     */
    public static function handleResponse(array $message, array $peer = []): bool
    {
        if (!isset($message['method']) || !is_numeric($message['method'])) {
            return false;
        }
        $cseq = self::parseCSeq($message['headers']['CSeq'][0] ?? '');
        if ($cseq === null || $cseq['method'] !== 'REGISTER') {
            return false;
        }
        $callId = (string)($message['headers']['Call-ID'][0] ?? $message['headers']['i'][0] ?? '');
        if ($callId === '') {
            return false;
        }
        $key = self::transactionKey($callId, $cseq['number']);
        $pending = self::$pending[$key] ?? null;
        if ($pending === null) {
            return false;
        }

        $via = $message['headers']['Via'][0] ?? $message['headers']['v'][0] ?? '';
        $viaData = sip::extractVia($via);
        if (($viaData['branch'] ?? '') !== $pending['branch']) {
            return false;
        }

        $pending['channel']->push(['message' => $message, 'peer' => $peer], 0.001);
        return true;
    }

    public static function pendingCount(): int
    {
        return count(self::$pending);
    }

    /** @internal Test seam; do not use for runtime configuration. */
    public static function setLocalIpResolverForTests(?callable $resolver): void
    {
        self::$localIpResolver = $resolver;
    }

    /** @return array{host:string,port:int} */
    public static function parseEndpoint(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            throw new \InvalidArgumentException('Servidor SIP vazio');
        }
        $withoutScheme = preg_replace('#^(?:sips?|udp)://#i', '', $input);
        $withoutScheme = preg_replace('#^sips?:#i', '', (string)$withoutScheme);
        $withoutScheme = preg_replace('#[;/].*$#', '', (string)$withoutScheme);
        $parts = sip::parseHostPort((string)$withoutScheme, self::DEFAULT_REMOTE_PORT);
        $host = trim((string)($parts['host'] ?? ''));
        $port = (int)($parts['port'] ?? self::DEFAULT_REMOTE_PORT);
        if ($host === '' || $port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Servidor SIP inválido');
        }
        return ['host' => $host, 'port' => $port];
    }

    /** @return array<string, mixed> */
    private static function buildRegister(
        string $username,
        string $domain,
        string $registrarHost,
        int $registrarPort,
        string $requestUri,
        string $contactIp,
        string $viaIp,
        string $callId,
        string $fromTag,
        int $cseq,
        string $branch,
        int $expires
    ): array {
        $addressOfRecord = self::sipUri($username, $domain, self::DEFAULT_REMOTE_PORT);
        $contact = self::sipUri($username, $contactIp, self::SIP_PORT);
        $viaHost = filter_var($viaIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? '[' . $viaIp . ']'
            : $viaIp;

        return [
            'method' => 'REGISTER',
            'methodForParser' => "REGISTER {$requestUri} SIP/2.0",
            'headers' => [
                'Via' => ["SIP/2.0/UDP {$viaHost}:" . self::SIP_PORT . ";branch={$branch};rport"],
                'From' => ["<{$addressOfRecord}>;tag={$fromTag}"],
                'To' => ["<{$addressOfRecord}>"],
                'Max-Forwards' => ['70'],
                'Call-ID' => [$callId],
                'CSeq' => ["{$cseq} REGISTER"],
                'Contact' => ["<{$contact}>"],
                'User-Agent' => ['SpechPhone'],
                'Expires' => [(string)max(0, $expires)],
                'Allow' => ['INVITE, ACK, CANCEL, OPTIONS, BYE, REFER, NOTIFY, MESSAGE, INFO, UPDATE'],
                'Content-Length' => ['0'],
            ],
        ];
    }

    private static function sendAndWait(
        object $transport,
        string $remoteIp,
        int $remotePort,
        string $packet,
        string $callId,
        int $cseq,
        string $branch,
        float $remaining
    ): ?array {
        if ($remaining <= 0) {
            return null;
        }
        $channel = new Channel(8);
        $key = self::transactionKey($callId, $cseq);
        self::$pending[$key] = [
            'branch' => $branch,
            'channel' => $channel,
            'created_at' => microtime(true),
        ];
        $deadline = microtime(true) + $remaining;
        $retransmit = 0.5;

        try {
            while (microtime(true) < $deadline) {
                $sent = $transport->sendto($remoteIp, $remotePort, $packet);
                if ($sent === false) {
                    return null;
                }

                $wait = min($retransmit, max(0.001, $deadline - microtime(true)));
                while ($wait > 0) {
                    $waitStarted = microtime(true);
                    $response = $channel->pop($wait);
                    if (is_array($response)) {
                        $code = (int)($response['message']['method'] ?? 0);
                        if ($code >= 100 && $code < 200) {
                            $wait -= microtime(true) - $waitStarted;
                            continue;
                        }
                        return $response;
                    }
                    break;
                }
                $retransmit = min($retransmit * 2, 4.0);
            }
            return null;
        } finally {
            unset(self::$pending[$key]);
            $channel->close();
        }
    }

    /** @return array{authorization_header:string,parameters:array<string,string>}|null */
    private static function challengeFromResponse(array $message): ?array
    {
        $code = (int)($message['method'] ?? 0);
        $challengeHeader = $code === 407 ? 'Proxy-Authenticate' : 'WWW-Authenticate';
        $authorizationHeader = $code === 407 ? 'Proxy-Authorization' : 'Authorization';
        $value = (string)($message['headers'][$challengeHeader][0] ?? '');
        if ($value === '') {
            return null;
        }
        $parameters = self::parseDigestParameters($value);
        if (($parameters['realm'] ?? '') === '' || ($parameters['nonce'] ?? '') === '') {
            return null;
        }
        return ['authorization_header' => $authorizationHeader, 'parameters' => $parameters];
    }

    /** @return array<string,string> */
    private static function parseDigestParameters(string $header): array
    {
        $header = preg_replace('/^\s*Digest\s+/i', '', trim($header));
        $result = [];
        preg_match_all('/([a-z][a-z0-9_-]*)\s*=\s*(?:"((?:\\\\.|[^"])*)"|([^,\s]+))/i', (string)$header, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $result[strtolower($match[1])] = isset($match[2]) && $match[2] !== ''
                ? stripcslashes($match[2])
                : (string)($match[3] ?? '');
        }
        return $result;
    }

    /** @param array<string,string> $challenge */
    private static function digestAuthorization(
        string $username,
        string $password,
        string $uri,
        string $method,
        array $challenge
    ): string {
        $realm = $challenge['realm'];
        $nonce = $challenge['nonce'];
        $algorithm = strtoupper($challenge['algorithm'] ?? 'MD5');
        if (!in_array($algorithm, ['MD5', 'MD5-SESS'], true)) {
            throw new \RuntimeException("Algoritmo Digest não suportado: {$algorithm}");
        }
        $qopOptions = array_map('trim', explode(',', strtolower($challenge['qop'] ?? '')));
        $qop = in_array('auth', $qopOptions, true) ? 'auth' : '';
        if (($challenge['qop'] ?? '') !== '' && $qop === '') {
            throw new \RuntimeException('O challenge Digest não oferece qop=auth');
        }
        $cnonce = bin2hex(random_bytes(8));
        $nc = '00000001';
        $ha1 = md5("{$username}:{$realm}:{$password}");
        if ($algorithm === 'MD5-SESS') {
            $ha1 = md5("{$ha1}:{$nonce}:{$cnonce}");
        }
        $ha2 = md5("{$method}:{$uri}");
        $response = $qop === ''
            ? md5("{$ha1}:{$nonce}:{$ha2}")
            : md5("{$ha1}:{$nonce}:{$nc}:{$cnonce}:{$qop}:{$ha2}");

        $quote = static fn(string $value): string => '"' . addcslashes($value, "\\\"") . '"';
        $parts = [
            'username=' . $quote($username),
            'realm=' . $quote($realm),
            'nonce=' . $quote($nonce),
            'uri=' . $quote($uri),
            'response=' . $quote($response),
            'algorithm=' . $algorithm,
        ];
        if (isset($challenge['opaque'])) {
            $parts[] = 'opaque=' . $quote($challenge['opaque']);
        }
        if ($qop !== '') {
            $parts[] = 'qop=' . $qop;
            $parts[] = 'nc=' . $nc;
            $parts[] = 'cnonce=' . $quote($cnonce);
        }
        return 'Digest ' . implode(', ', $parts);
    }

    /** @return array{0:?string,1:?int} */
    private static function observedEndpoint(array $response): array
    {
        $via = (string)($response['headers']['Via'][0] ?? $response['headers']['v'][0] ?? '');
        if ($via === '') {
            return [null, null];
        }
        $parsed = sip::extractVia($via);
        $received = isset($parsed['received']) && filter_var($parsed['received'], FILTER_VALIDATE_IP)
            ? $parsed['received']
            : null;
        $rport = isset($parsed['rport']) && ctype_digit((string)$parsed['rport'])
            ? (int)$parsed['rport']
            : null;
        return [$received, $rport];
    }

    /** @return array{confirmed:bool,contact:?string} */
    private static function bindingFromResponse(array $response, string $username): array
    {
        foreach (($response['headers']['Contact'] ?? $response['headers']['m'] ?? []) as $contact) {
            $uri = sip::extractURI($contact);
            $user = (string)($uri['user'] ?? '');
            $host = (string)($uri['peer']['host'] ?? '');
            $port = (int)($uri['peer']['port'] ?? 5060);
            if ($user === $username && $host !== '' && $port === self::SIP_PORT) {
                return ['confirmed' => true, 'contact' => "sip:{$username}@{$host}:" . self::SIP_PORT];
            }
        }
        return ['confirmed' => false, 'contact' => null];
    }

    /** @return array{number:int,method:string}|null */
    private static function parseCSeq(string $value): ?array
    {
        if (!preg_match('/^\s*(\d+)\s+([A-Z]+)\s*$/i', $value, $match)) {
            return null;
        }
        return ['number' => (int)$match[1], 'method' => strtoupper($match[2])];
    }

    private static function transactionKey(string $callId, int $cseq): string
    {
        return $callId . '|' . $cseq . '|REGISTER';
    }

    private static function localIp(): string
    {
        if (self::$localIpResolver !== null) {
            return (string)(self::$localIpResolver)();
        }
        return network::getLocalIp(4);
    }

    private static function configuredAdvertisedIp(): ?string
    {
        $configured = trim((string)getenv('SPECH_SIP_PUBLIC_IP'));
        return filter_var($configured, FILTER_VALIDATE_IP) ? $configured : null;
    }

    private static function sipUri(string $user, string $host, int $port): string
    {
        $host = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$host}]" : $host;
        $authority = $user === '' ? $host : "{$user}@{$host}";
        return 'sip:' . $authority . ($port === self::DEFAULT_REMOTE_PORT ? '' : ":{$port}");
    }

    /** @return array<string,mixed> */
    private static function result(bool $success, string $reason, ?int $code, float $startedAt, array $extra = []): array
    {
        return $extra + [
            'success' => $success,
            'reason' => $reason,
            'code' => $code,
            'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /** @return array<string,mixed> */
    private static function sanitizeResponse(array $response): array
    {
        foreach (['Authorization', 'Proxy-Authorization', 'WWW-Authenticate', 'Proxy-Authenticate'] as $header) {
            unset($response['headers'][$header]);
        }
        return $response;
    }
}
