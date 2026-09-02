<?php

namespace helpers\utils;

use libspech\Cli\cli;
use libspech\Network\network;
use libspech\Sip\trunkController;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;

/**
 * Centralises the bidirectional PCM ↔ RTP ↔ audio.php bridge.
 *
 * Used by both outgoing (startCall) and incoming (acceptIncomingCall) call paths.
 * Call startMedia() AFTER trunkController's codec/RTP properties are fully configured
 * and BEFORE (or right after) calling receiveMedia().
 */
class CallMediaBridge
{
    /**
     * Wire up the full media pipeline for a call.
     *
     * - Creates a local eventSock (UDP) for communicating with audio.php.
     * - Registers onReceivePcm so decoded RTP is forwarded to audio.php:9966.
     * - Calls $phone->receiveMedia() to start the RTP receive coroutine.
     * - Spawns a coroutine for the Browser-Mic → encode → RTP transmit loop.
     *
     * @param trunkController $phone Fully configured controller (ptUse, codecName,
     *                                    frequencyCall, audioRemoteIp, audioRemotePort set).
     * @param string $fingerprint Device fingerprint (fp).
     * @param string $userCodec Codec the browser uses, e.g. "PCMA/8000".
     */
    public static function startMedia(
        trunkController $phone,
        string          $fingerprint,
        string          $userCodec
    ): void
    {
        $freePort = network::getFreePort();
        $eventSock = new Socket(AF_INET, SOCK_DGRAM, 0);
        $phone->saveGlobalInfo('eventSock', $eventSock);
        $phone->globalInfo['eventSock']->bind('0.0.0.0', $freePort);
        $portHandler = $phone->globalInfo['eventSock']->getsockname()['port'];

        $parts = explode('/', $userCodec);
        $userFrequency = (int)($parts[1] ?? 8000);

        $phone->onReceivePcm(function ($pcmData, $peer, trunkController $ph, $codec, $frequency)
        use ($portHandler, $userFrequency) {
            if (strlen($pcmData) < 12) return;
            $id = implode(':', array_values($peer));
            $ph->globalInfo['eventSock']->sendto(
                '127.0.0.1',
                9966,
                "{$pcmData}__::__{$ph->callId}__::__{$id}__::__{$portHandler}__::__{$userFrequency}__::__{$frequency}"
            );
        });


        $phone->receiveMedia();
        cli::pcl("[Media] Recebendo áudio (RTP→PCM)", "green");

        Coroutine::create(function () use ($phone, $userFrequency) {
            cli::pcl("[Media] Iniciando loop Browser Mic → RTP", "green");

            // Handshake para registro do peer no audio.php
            $phone->globalInfo['eventSock']->sendto('127.0.0.1', 9966, str_repeat('0', 12));

            $pcmBuffer = '';
            $FRAME_MS = 20;
            $SRC_RATE = $userFrequency;
            $SAMPLES = (int)($SRC_RATE * ($FRAME_MS / 1000));
            $PCM_FRAME_BYTES = $SAMPLES * 2;

            while (true) {
                $peer = null;
                $data = $phone->globalInfo['eventSock']->recvfrom($peer, 0.2);

                if ($phone->receiveBye) break;
                if ($phone->error) break;
                if (!$phone->callActive) break;

                $e = explode('__::__', $data, 3);
                $pcmIn = $e[0];
                $pcmBuffer .= $pcmIn;

                while (strlen($pcmBuffer) >= $PCM_FRAME_BYTES) {
                    $pcmChunk = substr($pcmBuffer, 0, $PCM_FRAME_BYTES);
                    $pcmBuffer = substr($pcmBuffer, $PCM_FRAME_BYTES);

                    $encode = null;

                    if (strtoupper($phone->codecName) !== 'OPUS' && $SRC_RATE !== $phone->frequencyCall) {
                        $pcmChunk = resampler($pcmChunk, $SRC_RATE, $phone->frequencyCall);
                    }

                    switch (strtoupper($phone->codecName)) {
                        case 'PCMU':
                            $encode = encodePcmToPcmu($pcmChunk);
                            break;
                        case 'PCMA':
                            $encode = encodePcmToPcma($pcmChunk);
                            break;
                        case 'G729':
                            $encode = $phone->bcgChannel->encode($pcmChunk);
                            break;
                        case 'OPUS':
                            $pcm48 = resampler($pcmChunk, $SRC_RATE, 48000);
                            $memberKey =
                                array_keys($phone->mediaChannel->members, null, true)[0]
                                ?? array_key_first($phone->mediaChannel->members);
                            $encode = $phone->mediaChannel->members[$memberKey]['opus']
                                ->encode($pcm48, $SRC_RATE);
                            break;
                        case 'L16':
                            $encode = encodeL16FromPcm($pcmChunk);
                            break;
                        default:
                            continue 2;
                    }

                    if (!$encode) continue;

                    $packet = $phone->rtpChannel->buildAudioPacket($encode);
                    $phone->mediaChannel->socket->sendto(
                        $phone->audioRemoteIp,
                        $phone->audioRemotePort,
                        $packet
                    );
                }
            }

            cli::pcl("[Media] Loop Browser Mic encerrado", "red");
        });
    }
}
