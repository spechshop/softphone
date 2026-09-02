<?php

namespace helpers\utils;

use libspech\Sip\sip;

/**
 * In-memory transaction index owned by the main SIP dispatcher.
 *
 * There is deliberately no socket in this class. The index only correlates
 * packets already read by server.php's UDP :4000 listener.
 */
final class SipTransactionManager
{
    /** @var array<string, OutboundCall> */
    private array $transactions = [];

    public function add(string $callId, int $cseq, string $method, string $branch, OutboundCall $call): string
    {
        $key = self::key($callId, $cseq, $method, $branch);
        $this->transactions[$key] = $call;
        return $key;
    }

    public function remove(string $key): void
    {
        unset($this->transactions[$key]);
    }

    public function removeCall(string $callId): void
    {
        $prefix = $callId . '|';
        foreach (array_keys($this->transactions) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->transactions[$key]);
            }
        }
    }

    public function matchResponse(array $message): ?OutboundCall
    {
        if (!isset($message['method']) || !is_numeric($message['method'])) {
            return null;
        }
        $callId = self::header($message, 'Call-ID', 'i');
        $cseq = self::parseCSeq(self::header($message, 'CSeq'));
        $via = self::header($message, 'Via', 'v');
        $branch = (string)(sip::extractVia($via)['branch'] ?? '');
        if ($callId === '' || $cseq === null || $branch === '') {
            return null;
        }
        return $this->transactions[self::key($callId, $cseq['number'], $cseq['method'], $branch)] ?? null;
    }

    public function count(): int
    {
        return count($this->transactions);
    }

    public static function key(string $callId, int $cseq, string $method, string $branch): string
    {
        return $callId . '|' . $cseq . '|' . strtoupper($method) . '|' . $branch;
    }

    /** @return array{number:int,method:string}|null */
    public static function parseCSeq(string $value): ?array
    {
        if (!preg_match('/^\s*(\d+)\s+([A-Z]+)\s*$/i', $value, $match)) {
            return null;
        }
        return ['number' => (int)$match[1], 'method' => strtoupper($match[2])];
    }

    public static function tag(string $value): string
    {
        return preg_match('/(?:^|;)\s*tag=([^;>\s,]+)/i', $value, $match)
            ? trim($match[1])
            : '';
    }

    public static function header(array $message, string $name, ?string $compact = null): string
    {
        return (string)($message['headers'][$name][0] ?? ($compact === null ? '' : ($message['headers'][$compact][0] ?? '')));
    }
}
