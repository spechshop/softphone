<?php

namespace helpers\utils;

/**
 * Canonical SIP account identity. The browser fingerprint is the account id;
 * SIP usernames are attributes and must never be used as routing keys.
 */
class AccountIdentity
{
    public const VAULT_PATH = '/data/spechphone/devices.vault';

    /** @return array{accountId:string,fp:string,sipUser:string,sipDomain:string,sipServer:string,registrarHost:string,accountKey:string} */
    public static function fromData(string $fp, array $data): array
    {
        $server = trim((string)($data['sipServer'] ?? ''));
        $registrarHost = self::host($server);
        $domain = strtolower(trim((string)($data['sipDomain'] ?? '')) ?: $registrarHost);
        $user = trim((string)($data['sipUser'] ?? ''));

        return [
            'accountId' => $fp,
            'fp' => $fp,
            'sipUser' => $user,
            'sipDomain' => $domain,
            'sipServer' => $server,
            'registrarHost' => $registrarHost,
            'accountKey' => strtolower($user) . '@' . $domain . '|' . $registrarHost,
        ];
    }

    public static function get(string $fp): ?array
    {
        try {
            $vault = new \spechphoneVault(self::VAULT_PATH, (string)getenv('SPECH_VAULT_KEY_HEX'));
            $data = $vault->get($fp);
            return is_array($data) ? self::fromData($fp, $data) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,array> keyed by accountId */
    public static function all(): array
    {
        try {
            $vault = new \spechphoneVault(self::VAULT_PATH, (string)getenv('SPECH_VAULT_KEY_HEX'));
            $accounts = [];
            foreach ($vault->keys() as $fp) {
                $data = $vault->get($fp);
                if (is_array($data)) $accounts[$fp] = self::fromData($fp, $data);
            }
            return $accounts;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Resolve a local account without ever falling back to an ambiguous user.
     * Source host is only a registrar discriminator, never a substitute for a
     * conflicting destination domain.
     *
     * @return array{status:string,accountId:?string,account:?array,candidates:array}
     */
    public static function resolve(string $sipUser, string $sipDomain = '', string $sourceHost = '', ?array $accounts = null): array
    {
        $user = strtolower(trim($sipUser));
        $domain = strtolower(rtrim(trim($sipDomain), '.'));
        $source = strtolower(rtrim(trim($sourceHost), '.'));
        $accounts ??= self::all();

        $userCandidates = array_values(array_filter($accounts, static fn(array $a): bool =>
            strtolower((string)($a['sipUser'] ?? '')) === $user
        ));
        $candidates = $userCandidates;
        $identityMatched = false;

        if ($domain !== '') {
            $domainMatches = array_values(array_filter($userCandidates, static fn(array $a): bool =>
                in_array($domain, [
                    strtolower((string)($a['sipDomain'] ?? '')),
                    strtolower((string)($a['registrarHost'] ?? self::host((string)($a['sipServer'] ?? '')))),
                ], true)
            ));
            if ($domainMatches) {
                $candidates = $domainMatches;
                $identityMatched = true;
            }
        }

        if ($source !== '') {
            $serverMatches = array_values(array_filter($identityMatched ? $candidates : $userCandidates, static fn(array $a): bool =>
                self::hostMatches((string)($a['registrarHost'] ?? self::host((string)($a['sipServer'] ?? ''))), $source)
            ));
            if ($serverMatches) {
                $candidates = $serverMatches;
                $identityMatched = true;
            }
        }

        if (!$identityMatched || count($candidates) !== 1) {
            return [
                'status' => count($candidates) > 1 ? 'ambiguous' : 'not_found',
                'accountId' => null,
                'account' => null,
                'candidates' => array_values(array_map(static fn(array $a): string => (string)$a['accountId'], $candidates)),
            ];
        }

        return ['status' => 'resolved', 'accountId' => $candidates[0]['accountId'], 'account' => $candidates[0], 'candidates' => [$candidates[0]['accountId']]];
    }

    public static function sipUri(string $identity, string $fallbackDomain = ''): string
    {
        $value = trim($identity);
        if (preg_match('/sip:([^@;>\s]+)@([^;>\s]+)/i', $value, $m)) {
            return 'sip:' . $m[1] . '@' . strtolower(rtrim($m[2], '.'));
        }
        if (preg_match('/^([^@;>\s]+)@([^;>\s]+)$/', $value, $m)) {
            return 'sip:' . $m[1] . '@' . strtolower(rtrim($m[2], '.'));
        }
        $user = preg_replace('/^sip:/i', '', $value);
        return 'sip:' . $user . ($fallbackDomain !== '' ? '@' . strtolower(rtrim($fallbackDomain, '.')) : '');
    }

    /** @return array{user:string,domain:string,uri:string} */
    public static function parseSipIdentity(string $identity, string $fallbackDomain = ''): array
    {
        $uri = self::sipUri($identity, $fallbackDomain);
        preg_match('/^sip:([^@]+)(?:@(.+))?$/i', $uri, $m);
        return ['user' => $m[1] ?? '', 'domain' => strtolower($m[2] ?? ''), 'uri' => $uri];
    }

    public static function host(string $server): string
    {
        $server = trim($server);
        if ($server === '') return '';
        $host = parse_url(str_contains($server, '://') ? $server : 'sip://' . $server, PHP_URL_HOST);
        return strtolower(rtrim((string)($host ?: preg_replace('/:\d+$/', '', $server)), '.'));
    }

    private static function hostMatches(string $registrarHost, string $sourceHost): bool
    {
        $registrarHost = strtolower(rtrim($registrarHost, '.'));
        $sourceHost = strtolower(rtrim($sourceHost, '.'));
        if ($registrarHost === $sourceHost) return true;
        if (filter_var($sourceHost, FILTER_VALIDATE_IP) && !filter_var($registrarHost, FILTER_VALIDATE_IP)) {
            return in_array($sourceHost, gethostbynamel($registrarHost) ?: [], true);
        }
        return false;
    }
}
