<?php

namespace helpers\utils;

use libspech\Cli\cli;

/**
 * Coordinates all SpechPhone SIP signaling over the one server transport.
 */
final class PhoneController
{
    private static ?self $instance = null;
    private object $transport;
    private SipTransactionManager $transactions;
    /** @var array<string,OutboundCall> */
    private array $calls = [];
    /** @var array<string,OutboundCall> */
    private array $dialogs = [];
    /** @var array<string,array{packet:string,expires:float}> */
    private array $serverResponses = [];
    /** @var array<string,array{packet:string,ip:string,port:int,call_id:string,cseq:int,expires:float}> */
    private array $clientFinalAcks = [];

    private function __construct(object $transport)
    {
        $this->transport = $transport;
        $this->transactions = new SipTransactionManager();
    }

    public static function instance(?object $transport = null): self
    {
        if (self::$instance === null) {
            if ($transport === null) {
                throw new \LogicException('PhoneController transport has not been configured');
            }
            self::$instance = new self($transport);
        } elseif ($transport !== null) {
            self::$instance->transport = $transport;
        }
        return self::$instance;
    }

    /** @internal */
    public static function resetForTests(?object $transport = null): self
    {
        self::$instance = null;
        return self::instance($transport ?? new class {
            public function sendto(string $ip, int $port, string $packet): int|false { return strlen($packet); }
        });
    }

    /** @param array<string,mixed> $account */
    public function createOutboundCall(array $account, string $number, array $options = []): OutboundCall
    {
        $call = new OutboundCall($this, $account, $number, $options);
        $this->calls[$call->callId] = $call;
        return $call;
    }

    public function send(string $ip, int $port, string $packet, string $method, string $callId, int $cseq): bool
    {
        $safeMethod = strtoupper($method);
        cli::pcl("[SIP-TX] {$safeMethod} Call-ID={$callId} CSeq={$cseq} local=:4000 remote={$ip}:{$port}", 'cyan');
        return $this->transport->sendto($ip, $port, $packet) !== false;
    }

    public function registerTransaction(string $callId, int $cseq, string $method, string $branch, OutboundCall $call): string
    {
        return $this->transactions->add($callId, $cseq, $method, $branch, $call);
    }

    public function removeTransaction(string $key): void
    {
        $this->transactions->remove($key);
    }

    public function confirmDialog(OutboundCall $call): void
    {
        $this->dialogs[$call->dialog->confirmedKey()] = $call;
    }

    /**
     * Called once from server.php after REGISTER had the first chance to consume
     * its response. Returns true iff this controller owns the packet.
     */
    public function handlePacket(array $message, array $peer = []): bool
    {
        if (isset($message['method']) && is_numeric($message['method'])) {
            $call = $this->transactions->matchResponse($message);
            if ($call === null) {
                $this->expireProtocolCache();
                $key = self::serverTransactionKey($message);
                if ($key !== '' && isset($this->clientFinalAcks[$key])) {
                    $ack = $this->clientFinalAcks[$key];
                    $this->send($ack['ip'], $ack['port'], $ack['packet'], 'ACK', $ack['call_id'], $ack['cseq']);
                    return true;
                }
                return false;
            }
            $cseq = SipTransactionManager::parseCSeq(SipTransactionManager::header($message, 'CSeq'));
            cli::pcl('[SIP-RX] ' . (int)$message['method'] . ' Call-ID=' . $call->callId
                . ' CSeq=' . ($cseq['number'] ?? 0) . ' ' . ($cseq['method'] ?? ''), 'yellow');
            $call->enqueueResponse($message, $peer);
            return true;
        }

        $method = strtoupper((string)($message['method'] ?? ''));
        if (!in_array($method, ['BYE', 'INFO', 'REFER'], true)) {
            return false;
        }
        $this->expireProtocolCache();
        $requestKey = self::serverTransactionKey($message);
        if ($requestKey !== '' && isset($this->serverResponses[$requestKey])) {
            $callId = SipTransactionManager::header($message, 'Call-ID', 'i');
            $cseq = SipTransactionManager::parseCSeq(SipTransactionManager::header($message, 'CSeq'));
            $this->send((string)$peer['address'], (int)$peer['port'], $this->serverResponses[$requestKey]['packet'],
                '200/' . $method, $callId, $cseq['number'] ?? 0);
            return true;
        }
        $callId = SipTransactionManager::header($message, 'Call-ID', 'i');
        $localTag = SipTransactionManager::tag(SipTransactionManager::header($message, 'To', 't'));
        $remoteTag = SipTransactionManager::tag(SipTransactionManager::header($message, 'From', 'f'));
        $call = $this->dialogs[SipDialog::key($callId, $localTag, $remoteTag)] ?? null;
        if ($call === null) {
            return false;
        }
        return $call->handleInDialogRequest($message, $peer);
    }

    public function cleanup(OutboundCall $call): void
    {
        $this->transactions->removeCall($call->callId);
        unset($this->calls[$call->callId]);
        foreach ($this->dialogs as $key => $candidate) {
            if ($candidate === $call) unset($this->dialogs[$key]);
        }
    }

    public function activeDialogCount(): int { return count($this->dialogs); }
    public function activeCallCount(): int { return count($this->calls); }
    public function pendingTransactionCount(): int { return $this->transactions->count(); }
    public function transport(): object { return $this->transport; }

    public function rememberServerResponse(array $request, string $packet): void
    {
        $key = self::serverTransactionKey($request);
        if ($key === '') return;
        $this->serverResponses[$key] = ['packet' => $packet, 'expires' => microtime(true) + 32.0];
        if (count($this->serverResponses) > 1024) {
            uasort($this->serverResponses, static fn(array $a, array $b): int => $a['expires'] <=> $b['expires']);
            $this->serverResponses = array_slice($this->serverResponses, -1024, null, true);
        }
    }

    public function rememberClientFinalAck(array $response, string $packet, string $ip, int $port, int $cseq): void
    {
        $key = self::serverTransactionKey($response);
        if ($key === '') return;
        $this->clientFinalAcks[$key] = [
            'packet' => $packet, 'ip' => $ip, 'port' => $port,
            'call_id' => SipTransactionManager::header($response, 'Call-ID', 'i'),
            'cseq' => $cseq, 'expires' => microtime(true) + 32.0,
        ];
        if (count($this->clientFinalAcks) > 2048) {
            uasort($this->clientFinalAcks, static fn(array $a, array $b): int => $a['expires'] <=> $b['expires']);
            $this->clientFinalAcks = array_slice($this->clientFinalAcks, -2048, null, true);
        }
    }

    private function expireProtocolCache(): void
    {
        $now = microtime(true);
        foreach ($this->serverResponses as $key => $entry) {
            if ($entry['expires'] <= $now) unset($this->serverResponses[$key]);
        }
        foreach ($this->clientFinalAcks as $key => $entry) {
            if ($entry['expires'] <= $now) unset($this->clientFinalAcks[$key]);
        }
    }

    private static function serverTransactionKey(array $request): string
    {
        $callId = SipTransactionManager::header($request, 'Call-ID', 'i');
        $cseq = SipTransactionManager::parseCSeq(SipTransactionManager::header($request, 'CSeq'));
        $via = SipTransactionManager::header($request, 'Via', 'v');
        $branch = (string)(\libspech\Sip\sip::extractVia($via)['branch'] ?? '');
        if ($callId === '' || $cseq === null || $branch === '') return '';
        return SipTransactionManager::key($callId, $cseq['number'], $cseq['method'], $branch);
    }

    /** @param array<string,mixed> $account */
    public static function accountKey(array $account): string
    {
        $endpoint = SipRegisterManager::parseEndpoint((string)($account['sipServer'] ?? ''));
        $domain = trim((string)($account['sipDomain'] ?? '')) ?: $endpoint['host'];
        return substr(hash('sha256', strtolower(trim((string)($account['sipUser'] ?? ''))) . '|'
            . strtolower($domain) . '|' . strtolower($endpoint['host']) . ':' . $endpoint['port']), 0, 48);
    }
}
