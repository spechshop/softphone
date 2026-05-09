<?php

namespace helpers\utils;

use libspech\Rtp\MediaChannel;

class InboundCallSession
{
    public bool $receiveBye = false;
    public bool $callActive = true;
    public bool $error = false;
    public string $callId = '';
    public ?MediaChannel $mediaChannel = null;
    public string $remoteIp = '';
    public int $remotePort = 0;
    public int $ptTelephoneEvent = 101;
    public int $telephoneEventClockRate = 8000;
    public int $telephoneEventPtimeMs = 20;

    public function send2833(string $digit): void
    {
        if ($this->mediaChannel === null || $this->remoteIp === '' || $this->remotePort === 0) {
            return;
        }
        $this->mediaChannel->send2833(
            $digit,
            $this->remoteIp,
            $this->remotePort,
            $this->ptTelephoneEvent,
            $this->telephoneEventClockRate,
            $this->telephoneEventPtimeMs
        );
    }
}
