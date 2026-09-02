<?php

namespace helpers\utils;

/** Mutable state for one SIP dialog; media remains independently owned. */
final class SipDialog
{
    public string $callId;
    public string $accountKey;
    public string $direction = 'outbound';
    public string $localTag;
    public string $remoteTag = '';
    public int $localCSeq;
    public string $state = 'NEW';
    public string $requestUri;
    public string $remoteTarget;
    /** @var list<string> */
    public array $routeSet = [];
    public string $localSdp = '';
    /** @var array<string,mixed> */
    public array $remoteSdp = [];
    public int $localRtpPort = 0;
    public string $remoteRtpIp = '';
    public int $remoteRtpPort = 0;

    public function __construct(string $callId, string $accountKey, string $localTag, int $localCSeq, string $requestUri)
    {
        $this->callId = $callId;
        $this->accountKey = $accountKey;
        $this->localTag = $localTag;
        $this->localCSeq = $localCSeq;
        $this->requestUri = $requestUri;
        $this->remoteTarget = $requestUri;
    }

    public function confirmedKey(): string
    {
        return self::key($this->callId, $this->localTag, $this->remoteTag);
    }

    public static function key(string $callId, string $localTag, string $remoteTag): string
    {
        return $callId . '|' . $localTag . '|' . $remoteTag;
    }
}
