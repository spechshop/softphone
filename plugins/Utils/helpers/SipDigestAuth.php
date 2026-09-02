<?php

namespace helpers\utils;

/** Digest authentication shared by non-REGISTER client transactions. */
final class SipDigestAuth
{
    /** @return array{header:string,parameters:array<string,string>} */
    public static function challenge(array $message): array
    {
        $code = (int)($message['method'] ?? 0);
        $name = $code === 407 ? 'Proxy-Authenticate' : 'WWW-Authenticate';
        $authorization = $code === 407 ? 'Proxy-Authorization' : 'Authorization';
        $value = (string)($message['headers'][$name][0] ?? '');
        $parameters = self::parseParameters($value);
        if (($parameters['realm'] ?? '') === '' || ($parameters['nonce'] ?? '') === '') {
            throw new \RuntimeException('invalid_digest_challenge');
        }
        return ['header' => $authorization, 'parameters' => $parameters];
    }

    /** @param array<string,string> $challenge */
    public static function authorization(string $username, string $password, string $uri, string $method, array $challenge): string
    {
        $algorithm = strtoupper($challenge['algorithm'] ?? 'MD5');
        if (!in_array($algorithm, ['MD5', 'MD5-SESS'], true)) {
            throw new \RuntimeException('unsupported_digest_algorithm');
        }
        $realm = $challenge['realm'];
        $nonce = $challenge['nonce'];
        $qopOptions = array_map('trim', explode(',', strtolower($challenge['qop'] ?? '')));
        $qop = in_array('auth', $qopOptions, true) ? 'auth' : '';
        if (($challenge['qop'] ?? '') !== '' && $qop === '') {
            throw new \RuntimeException('unsupported_digest_qop');
        }
        $cnonce = bin2hex(random_bytes(8));
        $nc = '00000001';
        $ha1 = md5("{$username}:{$realm}:{$password}");
        if ($algorithm === 'MD5-SESS') $ha1 = md5("{$ha1}:{$nonce}:{$cnonce}");
        $ha2 = md5(strtoupper($method) . ":{$uri}");
        $response = $qop === ''
            ? md5("{$ha1}:{$nonce}:{$ha2}")
            : md5("{$ha1}:{$nonce}:{$nc}:{$cnonce}:{$qop}:{$ha2}");
        $quote = static fn(string $value): string => '"' . addcslashes($value, "\\\"") . '"';
        $parts = [
            'username=' . $quote($username), 'realm=' . $quote($realm),
            'nonce=' . $quote($nonce), 'uri=' . $quote($uri),
            'response=' . $quote($response), 'algorithm=' . $algorithm,
        ];
        if (isset($challenge['opaque'])) $parts[] = 'opaque=' . $quote($challenge['opaque']);
        if ($qop !== '') {
            $parts[] = 'qop=' . $qop;
            $parts[] = 'nc=' . $nc;
            $parts[] = 'cnonce=' . $quote($cnonce);
        }
        return 'Digest ' . implode(', ', $parts);
    }

    /** @return array<string,string> */
    private static function parseParameters(string $header): array
    {
        $header = preg_replace('/^\s*Digest\s+/i', '', trim($header));
        $result = [];
        preg_match_all('/([a-z][a-z0-9_-]*)\s*=\s*(?:"((?:\\\\.|[^"])*)"|([^,\s]+))/i', (string)$header, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $result[strtolower($match[1])] = isset($match[2]) && $match[2] !== ''
                ? stripcslashes($match[2]) : (string)($match[3] ?? '');
        }
        return $result;
    }
}
