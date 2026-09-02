<?php

namespace helpers\utils;

/**
 * Canonical SIP account identity. The browser fingerprint is the account id;
 * SIP usernames are attributes and must never be used as routing keys.
 */
class AccountIdentity
{
    private static bool $vaultInitialized = false;

    public static function vaultPath(): string
    {
        $file = DataPath::file('devices.vault');
        if (!self::$vaultInitialized) {
            $source = DataPath::migrateFirstExisting('devices.vault');
            if ($source !== null) {
                \libspech\Cli\cli::pcl("[DATA:MIGRATE] file=devices.vault source={$source} path={$file}", 'green');
            }
            self::$vaultInitialized = true;
        }
        return $file;
    }

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
            $vault = new \spechphoneVault(self::vaultPath(), (string)getenv('SPECH_VAULT_KEY_HEX'));
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
            $vault = new \spechphoneVault(self::vaultPath(), (string)getenv('SPECH_VAULT_KEY_HEX'));
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
     * WebSocket state is deliberately absent: SIP identity must also resolve
     * while every browser tab is closed.
     *
     * @return array{status:string,accountId:?string,account:?array,candidates:array}
     */
    public static function resolve(
        string $sipUser,
        string $sipDomain = '',
        string $sourceHost = '',
        ?array $accounts = null,
        string $requestUser = ''
    ): array
    {
        $user = strtolower(trim($sipUser));
        $domain = strtolower(rtrim(trim($sipDomain), '.'));
        $source = strtolower(rtrim(trim($sourceHost), '.'));
        $accounts ??= self::all();

        // The opaque Contact user is an accountId-derived routing token. It is
        // stronger than a rewritten To header and therefore resolves directly.
        if ($requestUser !== '') {
            $contactMatches = array_values(array_filter($accounts, static fn(array $a): bool =>
                hash_equals(self::contactUser((string)($a['accountId'] ?? '')), strtolower(trim($requestUser)))
            ));
            if (count($contactMatches) === 1) {
                return [
                    'status' => 'resolved_contact', 'accountId' => $contactMatches[0]['accountId'],
                    'account' => $contactMatches[0], 'candidates' => [$contactMatches[0]['accountId']],
                ];
            }
        }

        $userCandidates = array_values(array_filter($accounts, static fn(array $a): bool =>
            strtolower((string)($a['sipUser'] ?? '')) === $user
        ));
        $candidates = $userCandidates;
        if ($domain !== '') {
            $domainMatches = array_values(array_filter($userCandidates, static fn(array $a): bool =>
                in_array($domain, [
                    strtolower((string)($a['sipDomain'] ?? '')),
                    strtolower((string)($a['registrarHost'] ?? self::host((string)($a['sipServer'] ?? '')))),
                ], true)
            ));
            if ($domainMatches) {
                if (count($domainMatches) === 1) {
                    return self::resolved('resolved_domain', $domainMatches[0]);
                }
                $candidates = $domainMatches;
            }
        }

        if ($source !== '') {
            $serverMatches = array_values(array_filter($candidates, static fn(array $a): bool =>
                self::hostMatches((string)($a['registrarHost'] ?? self::host((string)($a['sipServer'] ?? ''))), $source)
            ));
            if (count($serverMatches) === 1) return self::resolved('resolved_registrar', $serverMatches[0]);
            if ($serverMatches) $candidates = $serverMatches;
        }

        // A rewritten Request-URI/domain is harmless only when the username
        // exists in exactly one local account. With duplicates it is ambiguous.
        if (count($userCandidates) === 1) return self::resolved('unique_user_fallback', $userCandidates[0]);

        return [
            'status' => count($userCandidates) > 1 ? 'ambiguous' : 'not_found',
            'accountId' => null,
            'account' => null,
            'candidates' => array_values(array_map(static fn(array $a): string => (string)$a['accountId'], $userCandidates)),
        ];
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

    public static function contactUser(string $accountId): string
    {
        return 'sp-' . substr(hash('sha256', $accountId), 0, 24);
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

    /** @return array{status:string,accountId:string,account:array,candidates:array} */
    private static function resolved(string $status, array $account): array
    {
        $accountId = (string)$account['accountId'];
        return ['status' => $status, 'accountId' => $accountId, 'account' => $account, 'candidates' => [$accountId]];
    }
}
