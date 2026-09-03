<?php

namespace helpers\utils;

use libspech\Cli\cli;
use libspech\Network\network;
use libspech\Rtp\MediaChannel;
use libspech\Sip\sip;
use Swoole\Coroutine\Channel;

/**
 * One outbound UAC dialog and its state machine.
 *
 * It never owns a SIP socket. All requests use PhoneController's transport and
 * all responses arrive through enqueueResponse() from the global dispatcher.
 */
final class OutboundCall
{
    public string $callId;
    public SipDialog $dialog;
    public ?MediaChannel $mediaChannel = null;
    public bool $receiveBye = false;
    public bool $callActive = false;
    public bool $error = false;
    public string $calledNumber;

    private PhoneController $controller;
    /** @var array<string,mixed> */
    private array $account;
    private array $endpoint;
    private string $remoteIp;
    private string $domain;
    private string $localIp;
    private string $contactIp;
    private Channel $events;
    private OutboundMediaSession $media;
    /** @var array<string,mixed> */
    private array $offerCodec;
    /** @var array<string,mixed> */
    private array $localOpusConfig;
    private string $fromHeader;
    private string $originalToHeader;
    private string $currentBranch = '';
    private string $currentInvitePacket = '';
    private string $currentInviteKey = '';
    /** @var array<int,array{branch:string,packet:string,request_uri:string}> */
    private array $inviteHistory = [];
    /** @var array<int,bool> */
    private array $processedChallenges = [];
    private bool $cancelRequested = false;
    private bool $cancelSent = false;
    private bool $byeSent = false;
    private bool $cleaned = false;
    private bool $eventsClosed = false;
    private bool $ringingNotified = false;
    private bool $inviteGotResponse = false;
    private float $inviteSentAt = 0.0;
    private float $lastInviteResponseAt = 0.0;
    private float $nextInviteRetransmitAt = 0.0;
    private float $inviteRetransmitInterval = 0.5;
    private float $terminationSentAt = 0.0;
    private float $noResponseTimeout;
    private float $provisionalTimeout;
    private $onRinging = null;
    private $onProgress = null;
    private $onAnswer = null;
    private $onFailed = null;
    private $onTerminated = null;

    /** @param array<string,mixed> $account */
    public function __construct(PhoneController $controller, array $account, string $number, array $options = [])
    {
        $this->controller = $controller;
        $this->account = $account;
        $this->calledNumber = trim($number);
        if ($this->calledNumber === '') throw new \InvalidArgumentException('empty_destination');
        foreach (['sipServer', 'sipUser', 'sipPass'] as $required) {
            if (trim((string)($account[$required] ?? '')) === '') throw new \InvalidArgumentException('invalid_sip_account');
        }
        $this->endpoint = SipRegisterManager::parseEndpoint((string)$account['sipServer']);
        $this->remoteIp = network::resolveAddress($this->endpoint['host'], 4);
        $this->domain = trim((string)($account['sipDomain'] ?? '')) ?: $this->endpoint['host'];
        $this->localIp = network::getLocalIp(4);
        $public = trim((string)getenv('SPECH_SIP_PUBLIC_IP'));
        $this->contactIp = filter_var($public, FILTER_VALIDATE_IP) ? $public : $this->localIp;
        $this->callId = bin2hex(random_bytes(16)) . '@' . $this->contactIp;
        $tag = bin2hex(random_bytes(8));
        $cseq = random_int(100, 99999);
        $requestUri = self::uri($this->calledNumber, $this->domain, $this->endpoint['port']);
        $this->dialog = new SipDialog($this->callId, PhoneController::accountKey($account), $tag, $cseq, $requestUri);
        $this->fromHeader = '<' . self::uri((string)$account['sipUser'], $this->domain) . ">;tag={$tag}";
        $this->originalToHeader = '<' . $requestUri . '>';
        $this->events = new Channel(128);
        $userCodec = (string)($options['userCodec'] ?? $account['codec'] ?? 'PCMA/8000');
        $this->offerCodec = self::codec((string)($options['trunkCodec'] ?? $account['trunkCodec'] ?? 'PCMA/8000'));
        $this->localOpusConfig = OpusConfig::normalize(
            is_array($options['opus'] ?? null) ? $options['opus'] : (is_array($account['opus'] ?? null) ? $account['opus'] : null)
        );
        $sourceChannels = strtoupper($this->offerCodec['name']) === 'OPUS'
            ? max(1, min(2, (int)($options['sourceChannels'] ?? $this->localOpusConfig['channels'])))
            : 1;
        // Hardware fallback is authoritative: never offer stereo when capture is mono.
        if ($sourceChannels === 1) {
            $this->localOpusConfig['channels'] = 1;
            $this->localOpusConfig['stereo'] = false;
        }
        $sourceSampleRate = max(8000, min(48000, (int)($options['sourceSampleRate']
            ?? (explode('/', $userCodec)[1] ?? 8000))));
        $this->media = new OutboundMediaSession(
            $this->callId,
            $userCodec,
            $this->offerCodec,
            $this->localOpusConfig,
            $sourceSampleRate,
            $sourceChannels,
        );
        $this->dialog->localRtpPort = $this->media->localPort;
        $this->dialog->localSdp = SdpHelper::buildLocalSdp(
            $this->contactIp,
            $this->dialog->localRtpPort,
            $this->offerCodec,
            null,
            $this->localOpusConfig,
            strtoupper($this->offerCodec['name']) === 'OPUS' ? $this->localOpusConfig['ptime'] : null,
        );
        $this->noResponseTimeout = (float)($options['noResponseTimeout'] ?? 15.0);
        $this->provisionalTimeout = (float)($options['provisionalTimeout'] ?? 120.0);
    }

    public function onRinging(callable $callback): self { $this->onRinging = $callback; return $this; }
    public function onProgress(callable $callback): self { $this->onProgress = $callback; return $this; }
    public function onAnswer(callable $callback): self { $this->onAnswer = $callback; return $this; }
    public function onFailed(callable $callback): self { $this->onFailed = $callback; return $this; }
    public function onTerminated(callable $callback): self { $this->onTerminated = $callback; return $this; }

    /** @return array<string,mixed>|null */
    public function effectiveOpusConfig(): ?array { return $this->media->effectiveOpusConfig(); }

    public function start(): bool
    {

        $this->dialog->state = 'CALLING';
        $this->sendInvite(null);
        try {
            while (!$this->cleaned) {
                $event = $this->events->pop(0.1);
                $now = microtime(true);
                if (is_array($event)) {
                    try {
                        $this->processResponse($event['message'], $event['peer']);
                    } catch (\Throwable) {
                        $this->fail('invalid_sip_response', 500);
                    }
                    if ($this->cleaned) break;
                }

                if ($this->cancelRequested && $this->inviteGotResponse && !$this->cancelSent
                    && !$this->callActive && $this->dialog->remoteTag === '') {
                    $this->sendCancel();
                } elseif ($this->cancelRequested && $this->callActive && !$this->byeSent) {
                    $this->sendBye();
                }

                if (!$this->inviteGotResponse && !$this->cancelSent && $now >= $this->nextInviteRetransmitAt) {
                    $this->sendCurrentInvite();
                    $this->inviteRetransmitInterval = min($this->inviteRetransmitInterval * 2, 4.0);
                    $this->nextInviteRetransmitAt = $now + $this->inviteRetransmitInterval;
                }
                if (!$this->inviteGotResponse && ($now - $this->inviteSentAt) >= $this->noResponseTimeout) {
                    $this->fail('timeout_no_response', 408);
                } elseif (!$this->callActive && !$this->cancelSent && $this->inviteGotResponse
                    && ($now - $this->lastInviteResponseAt) >= $this->provisionalTimeout) {
                    $this->fail('timeout_after_provisional', 408);
                } elseif ($this->cancelSent && $this->terminationSentAt > 0
                    && ($now - $this->terminationSentAt) >= $this->provisionalTimeout) {
                    $this->terminate('cancel_timeout');
                } elseif ($this->byeSent && $this->terminationSentAt > 0
                    && ($now - $this->terminationSentAt) >= $this->noResponseTimeout) {
                    $this->terminate('bye_timeout');
                } elseif ($this->callActive && $this->receiveBye) {
                    $this->terminate('remote_bye');
                }
            }
        } finally {
            if (!$this->cleaned) $this->terminate('stopped');
        }
        return !$this->error && $this->dialog->state === 'TERMINATED';
    }

    public function enqueueResponse(array $message, array $peer): void
    {
        if (!$this->eventsClosed) $this->events->push(['message' => $message, 'peer' => $peer], 0.001);
    }

    public function hangup(): void
    {
        if ($this->cleaned) return;
        $this->cancelRequested = true;
        if ($this->callActive) $this->sendBye();
        elseif ($this->dialog->state !== 'NEW' && $this->inviteGotResponse) $this->sendCancel();
    }

    public function sendDtmf(string $digit): void { $this->media->sendDtmf($digit); }

    public function close(): void { $this->hangup(); }

    public function handleInDialogRequest(array $message, array $peer): bool
    {
        $method = strtoupper((string)$message['method']);
        if ($method === 'BYE') {
            $packet = $this->response($message, 200, 'OK');
            $this->controller->rememberServerResponse($message, $packet);
            $cseq = SipTransactionManager::parseCSeq(SipTransactionManager::header($message, 'CSeq'));
            $this->controller->send((string)$peer['address'], (int)$peer['port'], $packet, '200/BYE', $this->callId, $cseq['number'] ?? 0);
            $this->receiveBye = true;
            $this->callActive = false;
            $this->terminate('remote_bye');
            return true;
        }
        if ($method === 'INFO') {
            $packet = $this->response($message, 200, 'OK');
            $cseq = SipTransactionManager::parseCSeq(SipTransactionManager::header($message, 'CSeq'));
            $this->controller->send((string)$peer['address'], (int)$peer['port'], $packet, '200/INFO', $this->callId, $cseq['number'] ?? 0);
            return true;
        }
        if ($method === 'REFER') {
            // The signaling transaction is acknowledged on :4000. Transfer
            // execution is intentionally left to a future application policy.
            $packet = $this->response($message, 202, 'Accepted');
            $cseq = SipTransactionManager::parseCSeq(SipTransactionManager::header($message, 'CSeq'));
            $this->controller->send((string)$peer['address'], (int)$peer['port'], $packet, '202/REFER', $this->callId, $cseq['number'] ?? 0);
            return true;
        }
        return false;
    }

    private function processResponse(array $message, array $peer): void
    {
        $code = (int)$message['method'];
        $cseq = SipTransactionManager::parseCSeq(SipTransactionManager::header($message, 'CSeq'));
        if ($cseq === null) return;
        if ($cseq['method'] === 'INVITE' && $cseq['number'] === $this->dialog->localCSeq) {
            $this->inviteGotResponse = true;
            $this->lastInviteResponseAt = microtime(true);
        }
        if ($cseq['method'] === 'CANCEL') {
            return; // 200 CANCEL does not terminate the INVITE transaction.
        }
        if ($cseq['method'] === 'BYE') {
            if ($code >= 200 && $code < 300) $this->terminate('local_bye');
            elseif ($code >= 300) $this->terminate('bye_failed_' . $code);
            return;
        }
        if ($cseq['method'] !== 'INVITE') return;

        if ($code >= 100 && $code < 200) {
            $this->dialog->state = $code === 100 ? 'PROCEEDING' : 'EARLY';
            if (isset($message['sdp'])) $this->dialog->remoteSdp = $message['sdp'];
            if (isset($message['sdp'])) {
                $this->media->start($this->dialog->remoteSdp);
                $this->mediaChannel = $this->media->mediaChannel;
            }
            if (($code === 180 || $code === 183) && !$this->ringingNotified) {
                $this->ringingNotified = true;
                if (is_callable($this->onRinging)) ($this->onRinging)($this, $code);
            }
            if (is_callable($this->onProgress)) ($this->onProgress)($this, $code, $message);
            return;
        }

        if ($code === 401 || $code === 407) {
            $history = $this->inviteHistory[$cseq['number']] ?? null;
            if ($history !== null) $this->sendNegativeAck($message, $cseq['number'], $history);
            if (isset($this->processedChallenges[$cseq['number']])) return;
            $this->processedChallenges[$cseq['number']] = true;
            if (count($this->processedChallenges) > 2) {
                $this->fail('authentication_failed', $code);
                return;
            }
            try {
                $challenge = SipDigestAuth::challenge($message);
                $authorization = [
                    'name' => $challenge['header'],
                    'value' => SipDigestAuth::authorization((string)$this->account['sipUser'], (string)$this->account['sipPass'],
                        $this->dialog->requestUri, 'INVITE', $challenge['parameters']),
                ];
            } catch (\Throwable $exception) {
                $this->fail($exception->getMessage(), $code);
                return;
            }
            $this->dialog->localCSeq++;
            $this->sendInvite($authorization);
            return;
        }

        if ($code >= 200 && $code < 300) {
            $this->confirmFrom200($message);
            $this->sendSuccessAck($message);
            if ($this->byeSent) return; // retransmitted 2xx after local hangup: ACK only.
            if ($this->cancelSent || $this->cancelRequested) {
                $this->sendBye(); // CANCEL/200 crossing: ACK first, then BYE.
                return;
            }
            if ($this->callActive) return; // duplicate 2xx was ACKed idempotently.
            if (empty($this->dialog->remoteSdp)) {
                $this->fail('missing_sdp_in_2xx', 488);
                return;
            }
            try {
                $this->media->start($this->dialog->remoteSdp);
                $this->mediaChannel = $this->media->mediaChannel;
            } catch (\Throwable $exception) {
                $this->sendBye();
                $this->fail('media_start_failed', 488);
                return;
            }
            $this->callActive = true;
            $this->dialog->state = 'ESTABLISHED';
            cli::pcl("[DIALOG] ESTABLISHED Call-ID={$this->callId}", 'green');
            if (is_callable($this->onAnswer)) ($this->onAnswer)($this, $message);
            return;
        }

        if ($code >= 300 && $code < 700) {
            $history = $this->inviteHistory[$cseq['number']] ?? null;
            if ($history !== null) $this->sendNegativeAck($message, $cseq['number'], $history);
            if ($code === 487 && $this->cancelSent) $this->terminate('cancelled');
            else $this->fail('sip_' . $code, $code);
        }
    }

    /** @param null|array{name:string,value:string} $authorization */
    private function sendInvite(?array $authorization): void
    {
        $this->currentBranch = self::branch();
        $headers = $this->baseHeaders($this->dialog->localCSeq, 'INVITE', $this->currentBranch, $this->originalToHeader);
        $headers['Contact'] = ['<' . self::uri((string)$this->account['sipUser'], $this->contactIp, SipRegisterManager::SIP_PORT) . '>'];
        $headers['Allow'] = ['INVITE, ACK, CANCEL, OPTIONS, BYE, REFER, MESSAGE, INFO'];
        $headers['Supported'] = ['replaces, timer'];
        $headers['Content-Type'] = ['application/sdp'];
        if ($authorization !== null) $headers[$authorization['name']] = [$authorization['value']];
        $model = ['method' => 'INVITE', 'methodForParser' => "INVITE {$this->dialog->requestUri} SIP/2.0",
            'headers' => $headers, 'body' => $this->dialog->localSdp];
        $this->currentInvitePacket = sip::renderSolution($model, $this->contactIp);
        $this->currentInviteKey = $this->controller->registerTransaction($this->callId, $this->dialog->localCSeq,
            'INVITE', $this->currentBranch, $this);
        $this->inviteHistory[$this->dialog->localCSeq] = [
            'branch' => $this->currentBranch, 'packet' => $this->currentInvitePacket,
            'request_uri' => $this->dialog->requestUri,
        ];
        $this->inviteGotResponse = false;
        $this->inviteSentAt = microtime(true);
        $this->lastInviteResponseAt = $this->inviteSentAt;
        $this->inviteRetransmitInterval = 0.5;
        $this->nextInviteRetransmitAt = $this->inviteSentAt + $this->inviteRetransmitInterval;
        $this->sendCurrentInvite($authorization !== null);
    }

    private function sendCurrentInvite(bool $authenticated = false): void
    {
        $label = $authenticated ? 'INVITE(auth)' : 'INVITE';
        $this->controller->send($this->remoteIp, $this->endpoint['port'], $this->currentInvitePacket,
            $label, $this->callId, $this->dialog->localCSeq);
    }

    private function sendNegativeAck(array $response, int $cseq, array $history): void
    {
        $headers = $this->baseHeaders($cseq, 'ACK', $history['branch'], SipTransactionManager::header($response, 'To', 't'));
        $packet = sip::renderSolution(['method' => 'ACK', 'methodForParser' => "ACK {$history['request_uri']} SIP/2.0", 'headers' => $headers], $this->contactIp);
        $this->controller->rememberClientFinalAck($response, $packet, $this->remoteIp, $this->endpoint['port'], $cseq);
        $this->controller->send($this->remoteIp, $this->endpoint['port'], $packet, 'ACK', $this->callId, $cseq);
    }

    private function confirmFrom200(array $response): void
    {
        $this->dialog->remoteTag = SipTransactionManager::tag(SipTransactionManager::header($response, 'To', 't'));
        if ($this->dialog->remoteTag === '') throw new \RuntimeException('missing_remote_tag');
        $contact = SipTransactionManager::header($response, 'Contact', 'm');
        if ($contact !== '') $this->dialog->remoteTarget = self::extractUri($contact);
        $this->dialog->routeSet = array_values(array_reverse($response['headers']['Record-Route'] ?? []));
        if (isset($response['sdp'])) {
            $this->dialog->remoteSdp = $response['sdp'];
            $parsed = SdpHelper::parseRemoteSdp($response['sdp']);
            $this->dialog->remoteRtpIp = $parsed['ip'];
            $this->dialog->remoteRtpPort = $parsed['port'];
        }
        $this->controller->confirmDialog($this);
    }

    private function sendSuccessAck(array $response): void
    {
        [$requestUri, $routes, $ip, $port] = $this->dialogRoute();
        $branch = self::branch();
        $headers = $this->baseHeaders($this->dialog->localCSeq, 'ACK', $branch,
            $this->originalToHeader . ';tag=' . $this->dialog->remoteTag);
        if ($routes !== []) $headers['Route'] = $routes;
        $packet = sip::renderSolution(['method' => 'ACK', 'methodForParser' => "ACK {$requestUri} SIP/2.0", 'headers' => $headers], $this->contactIp);
        $this->controller->rememberClientFinalAck($response, $packet, $ip, $port, $this->dialog->localCSeq);
        $this->controller->send($ip, $port, $packet, 'ACK', $this->callId, $this->dialog->localCSeq);
    }

    private function sendCancel(): void
    {
        if ($this->cancelSent || $this->callActive || $this->dialog->remoteTag !== '' || $this->currentBranch === '') return;
        $this->cancelSent = true;
        $headers = $this->baseHeaders($this->dialog->localCSeq, 'CANCEL', $this->currentBranch, $this->originalToHeader);
        $packet = sip::renderSolution(['method' => 'CANCEL', 'methodForParser' => "CANCEL {$this->dialog->requestUri} SIP/2.0", 'headers' => $headers], $this->contactIp);
        $this->controller->registerTransaction($this->callId, $this->dialog->localCSeq, 'CANCEL', $this->currentBranch, $this);
        $this->dialog->state = 'CANCELLING';
        $this->terminationSentAt = microtime(true);
        $this->controller->send($this->remoteIp, $this->endpoint['port'], $packet, 'CANCEL', $this->callId, $this->dialog->localCSeq);
    }

    private function sendBye(): void
    {
        if ($this->byeSent || $this->dialog->remoteTag === '') return;
        $this->byeSent = true;
        $this->callActive = false;
        $this->dialog->localCSeq++;
        [$requestUri, $routes, $ip, $port] = $this->dialogRoute();
        $branch = self::branch();
        $headers = $this->baseHeaders($this->dialog->localCSeq, 'BYE', $branch,
            $this->originalToHeader . ';tag=' . $this->dialog->remoteTag);
        if ($routes !== []) $headers['Route'] = $routes;
        $packet = sip::renderSolution(['method' => 'BYE', 'methodForParser' => "BYE {$requestUri} SIP/2.0", 'headers' => $headers], $this->contactIp);
        $this->controller->registerTransaction($this->callId, $this->dialog->localCSeq, 'BYE', $branch, $this);
        $this->dialog->state = 'TERMINATING';
        $this->terminationSentAt = microtime(true);
        $this->media->close();
        $this->controller->send($ip, $port, $packet, 'BYE', $this->callId, $this->dialog->localCSeq);
    }

    /** @return array<string,list<string>> */
    private function baseHeaders(int $cseq, string $method, string $branch, string $to): array
    {
        $viaIp = filter_var($this->localIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $this->localIp . ']' : $this->localIp;
        return [
            'Via' => ["SIP/2.0/UDP {$viaIp}:4000;branch={$branch};rport"],
            'Max-Forwards' => ['70'], 'From' => [$this->fromHeader], 'To' => [$to],
            'Call-ID' => [$this->callId], 'CSeq' => ["{$cseq} {$method}"],
            'User-Agent' => ['SpechPhone'], 'Content-Length' => ['0'],
        ];
    }

    /** @return array{0:string,1:list<string>,2:string,3:int} */
    private function dialogRoute(): array
    {
        $requestUri = $this->dialog->remoteTarget;
        $routes = $this->dialog->routeSet;
        $next = $requestUri;
        if ($routes !== []) {
            $firstUri = self::extractUri($routes[0]);
            if (preg_match('/;lr(?:[;>]|$)/i', $firstUri)) {
                $next = $firstUri;
            } else {
                $requestUri = $firstUri;
                array_shift($routes);
                $routes[] = '<' . $this->dialog->remoteTarget . '>';
                $next = $firstUri;
            }
        }
        $peer = sip::extractURI($next)['peer'] ?? [];
        $host = (string)($peer['host'] ?? $this->endpoint['host']);
        $port = (int)($peer['port'] ?? 5060);
        return [$requestUri, $routes, network::resolveAddress($host, 4), $port];
    }

    private function fail(string $reason, int $code): void
    {
        if ($this->cleaned) return;
        $this->error = true;
        if (is_callable($this->onFailed)) ($this->onFailed)($this, $reason, $code);
        $this->terminate($reason);
    }

    private function terminate(string $reason): void
    {
        if ($this->cleaned) return;
        $this->cleaned = true;
        $this->callActive = false;
        $this->receiveBye = true;
        $this->dialog->state = 'TERMINATED';
        $this->media->close();
        $this->mediaChannel = null;
        $this->controller->cleanup($this);
        if (!$this->eventsClosed) {
            $this->eventsClosed = true;
            $this->events->close();
        }
        cli::pcl("[DIALOG] TERMINATED Call-ID={$this->callId} reason={$reason}", 'yellow');
        if (is_callable($this->onTerminated)) ($this->onTerminated)($this, $reason);
    }

    private function response(array $request, int $code, string $reason): string
    {
        $headers = [];
        foreach (['Via', 'From', 'To', 'Call-ID', 'CSeq'] as $name) $headers[$name] = $request['headers'][$name] ?? [''];
        return sip::renderSolution(['method' => (string)$code, 'methodForParser' => "SIP/2.0 {$code} {$reason}", 'headers' => $headers], $this->contactIp);
    }

    /** @return array{name:string,pt:int,rate:int,channels:int} */
    private static function codec(string $value): array
    {
        $parts = explode('/', strtoupper(trim($value)));
        $name = $parts[0] ?: 'PCMA';
        $defaults = ['PCMU' => [0,8000,1], 'GSM' => [3,8000,1], 'PCMA' => [8,8000,1],
            'G729' => [18,8000,1], 'OPUS' => [111,48000,2], 'L16' => [96,8000,1]];
        [$pt,$rate,$channels] = $defaults[$name] ?? $defaults['PCMA'];
        if (isset($parts[1]) && (int)$parts[1] > 0) $rate = (int)$parts[1];
        if (isset($parts[2]) && (int)$parts[2] > 0) $channels = (int)$parts[2];
        return ['name' => $name, 'pt' => $pt, 'rate' => $rate, 'channels' => $channels];
    }

    private static function branch(): string { return 'z9hG4bK-' . bin2hex(random_bytes(12)); }

    private static function extractUri(string $header): string
    {
        if (preg_match('/<\s*(sips?:[^>]+)>/i', $header, $match)) return trim($match[1]);
        if (preg_match('/(sips?:[^\s,]+)/i', $header, $match)) return trim($match[1]);
        return $header;
    }

    private static function uri(string $user, string $host, int $port = 5060): string
    {
        $host = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$host}]" : $host;
        return 'sip:' . ($user !== '' ? $user . '@' : '') . $host . ($port === 5060 ? '' : ':' . $port);
    }
}
