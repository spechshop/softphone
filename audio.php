<?php

use libspech\Cache\cache;
use libspech\Cli\cli;
use Swoole\Coroutine\Socket;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use helpers\utils\MicUplinkFrame;
use helpers\utils\MicUplinkSession;

\Swoole\Runtime::enableCoroutine();

ini_set('memory_limit', '4024M');

include 'libspech/plugins/autoloader.php';
require_once __DIR__ . '/plugins/Utils/helpers/MicUplinkFrame.php';
require_once __DIR__ . '/plugins/Utils/helpers/MicQualityMetrics.php';
require_once __DIR__ . '/plugins/Utils/helpers/MicJitterBuffer.php';
require_once __DIR__ . '/plugins/Utils/helpers/RtpPacer.php';
require_once __DIR__ . '/plugins/Utils/helpers/MicUplinkSession.php';
require_once __DIR__.'/plugins/Utils/helpers/OpusConfig.php';

$clients = [];
$clientInfo = [];
$buffers = [];
$lastSeen = [];
$lastLog = [];
$lastLogHandshake = [];
$lastLogPeer = [];
$lastLogNoClient = [];
$frameQueue = [];
$jitterBuffer = [];
$packetTimestamps = [];
$streamKeys = [];
$udpPeers = [];
$udpSendSockets = [];
$micUplinkSessions = [];

$BUFFER_TARGET = 6;
$JITTER_BUFFER_SIZE = 5;
$FRAME_TARGET = 320;
$MAX_SILENCE = 30;
$MIC_JITTER_TARGET_MS = max(40, min(100, (int)(getenv('MIC_JITTER_TARGET_MS') ?: 60)));
$MIC_MAX_FRAME_AGE_MS = max(160, min(200, (int)(getenv('MIC_MAX_FRAME_AGE_MS') ?: 180)));

$channelDecode = [];

cache::define('rateCall', 8000);

$cfNamefile='plugins/configInterface.json';
$configInterface = json_decode(file_get_contents($cfNamefile), true);

/**
 * Configuração do servidor WebSocket de áudio.
 */
$server = new Server("0.0.0.0", 8889, SWOOLE_BASE, SWOOLE_SOCK_TCP | SWOOLE_SSL);

$server->set([
    'ssl_cert_file' => $configInterface['serverSettings']['ssl_cert_file'],
    'ssl_key_file' => $configInterface['serverSettings']['ssl_key_file'],
    'enable_coroutine' => true,

]);

/**
 * Retorna um UDP socket persistente por FD.
 * O socket só é criado quando aquele FD realmente precisa enviar áudio para UDP.
 */
function getUdpSendSocketForFd(int $fd, array &$udpSendSockets): Socket
{
    if (
        !isset($udpSendSockets[$fd]) ||
        !$udpSendSockets[$fd] instanceof Socket
    ) {
        $udpSendSockets[$fd] = new Socket(AF_INET, SOCK_DGRAM, 0);
    }

    return $udpSendSockets[$fd];
}

/**
 * Fecha e remove o UDP socket vinculado ao FD.
 */
function closeUdpSendSocketForFd(int $fd, array &$udpSendSockets): void
{
    if (!isset($udpSendSockets[$fd])) {
        return;
    }

    try {
        if ($udpSendSockets[$fd] instanceof Socket) {
            $udpSendSockets[$fd]->close();
        }
    } catch (Throwable $e) {
        echo "⚠️ Erro ao fechar UDP socket do FD {$fd}: {$e->getMessage()}\n";
    }

    unset($udpSendSockets[$fd]);
}

/** Resample PCM16LE without collapsing/interleaving stereo as a mono timeline. */
function resamplePcmChannels(string $pcm, int $sourceRate, int $targetRate, int $channels): string
{
    return \helpers\utils\OpusConfig::resamplePcm($pcm, $sourceRate, $targetRate, $channels);
}

/**
 * Envia PCM do browser para os peers UDP/libspech da mesma stream.
 */
function sendBrowserPcmToUdpPeers(
    int    $fd,
    string $stream,
    string $ssrc,
    string $packet,
    array  &$udpPeers,
    array  &$udpSendSockets
): void
{
    if (empty($udpPeers[$stream])) {
        return;
    }

    $udp = getUdpSendSocketForFd($fd, $udpSendSockets);

    foreach ($udpPeers[$stream] as $peerKey => $peerInfo) {
        if ($peerKey === $ssrc) {
            continue;
        }

        if (empty($peerInfo['host']) || empty($peerInfo['port'])) {
            continue;
        }

        // cli::pcl("Port ".$udp->getsockname()['port']." sending packet to {$peerInfo['host']}:{$peerInfo['port']}", 'cyan');
        $udp->sendto(
            $peerInfo['host'],
            (int)$peerInfo['port'],
            $packet
        );
    }
}

/**
 * Relay direto browser -> browser.
 *
 * Usado quando os dois lados estão no spechphone e não existe peer UDP/libspech
 * para carregar o áudio de volta.
 */
function relayBrowserToBrowser(
    Server $server,
    int    $sourceFd,
    string $stream,
    string $sourceSsrc,
    string $pcmData,
    array  &$clients,
    array  &$clientInfo
): void
{
    if (!str_starts_with($sourceSsrc, 'mic-')) {
        return;
    }

    $senderFp = substr($sourceSsrc, 4);

    foreach ($clients[$stream] ?? [] as $targetFd) {
        if ($targetFd === $sourceFd) {
            continue;
        }

        $targetSsrc = $clientInfo[$targetFd]['ssrc'] ?? '';

        if (!str_starts_with($targetSsrc, 'rx-')) {
            continue;
        }

        // Evita echo para o próprio usuário/dispositivo.
        if (substr($targetSsrc, 3) === $senderFp) {
            continue;
        }

        if (method_exists($server, 'isEstablished') && !$server->isEstablished($targetFd)) {
            continue;
        }

        $server->push($targetFd, $pcmData, SWOOLE_WEBSOCKET_OPCODE_BINARY);
    }
}

/** Monotonic milliseconds used for capture transit, deadlines and pacing metrics. */
function micMonotonicMs(): float
{
    return hrtime(true) / 1_000_000;
}

/**
 * One lightweight coroutine per microphone stream. It releases no more than one
 * PCM frame per negotiated internal 10/20 ms deadline, regardless of bursts.
 */
function startMicUplinkPacer(
    Server             $server,
    MicUplinkSession   $session,
    array              &$udpPeers,
    array              &$udpSendSockets,
    array              &$clients,
    array              &$clientInfo
): void {
    \Swoole\Coroutine::create(function () use (
        $server,
        $session,
        &$udpPeers,
        &$udpSendSockets,
        &$clients,
        &$clientInfo
    ): void {
        while ($session->active) {
            $nowMs = micMonotonicMs();
            if (!$session->startIfReady($nowMs)) {
                \Swoole\Coroutine::sleep(0.005);
                continue;
            }

            $deadline = $session->pacer->deadlineMs();
            if ($deadline !== null && $nowMs < $deadline) {
                \Swoole\Coroutine::sleep(max(0.001, min(0.02, ($deadline - $nowMs) / 1000)));
                continue;
            }

            $pcmData = $session->tick($nowMs);
            if ($pcmData !== null) {
                $packet = $pcmData . '__::__' . $session->stream . '__::__' . $session->ssrc;
                sendBrowserPcmToUdpPeers(
                    $session->fd,
                    $session->stream,
                    $session->ssrc,
                    $packet,
                    $udpPeers,
                    $udpSendSockets
                );
                relayBrowserToBrowser(
                    $server,
                    $session->fd,
                    $session->stream,
                    $session->ssrc,
                    $pcmData,
                    $clients,
                    $clientInfo
                );
            }

            if ($nowMs - $session->lastMetricsAtMs >= 1000) {
                $snapshot = $session->snapshot();
                if (isset($clientInfo[$session->fd])
                    && (!method_exists($server, 'isEstablished') || $server->isEstablished($session->fd))) {
                    $server->push($session->fd, json_encode([
                        'type' => 'micQuality',
                        'data' => $snapshot,
                    ], JSON_UNESCAPED_SLASHES));
                }
                $session->lastMetricsAtMs = $nowMs;

                if ($nowMs - $session->lastLogAtMs >= 10000) {
                    $dropCount = ($snapshot['lateFramesDropped'] ?? 0) + ($snapshot['droppedFrames'] ?? 0);
                    $wsKb = round(($snapshot['wsBufferedAmount'] ?? 0) / 1024, 1);
                    cli::pcl(
                        "[MIC:QUALITY] callId={$session->stream} quality={$snapshot['quality']} "
                        . "jitter={$snapshot['uplinkJitterP95']}ms queue="
                        . ($snapshot['browserQueueMs'] ?? 0) . "ms drops={$dropCount} "
                        . "wsBuffered={$wsKb}KB pacerP95={$snapshot['rtpPacingGapP95']}ms",
                        'cyan'
                    );
                    $session->lastLogAtMs = $nowMs;
                }
            }
        }
    });
}

/**
 * Start.
 */
$server->on("start", function (Server $server) use (
    &$clientInfo,
    &$clients,
    &$udpPeers,
    &$buffers,
    &$frameQueue,
    &$jitterBuffer,
    &$packetTimestamps,
    &$streamKeys,
    &$lastSeen,
    &$lastLog,
    &$lastLogHandshake,
    &$lastLogPeer,
    &$lastLogNoClient,
    &$channelDecode,
    $BUFFER_TARGET,
    $JITTER_BUFFER_SIZE,
    $FRAME_TARGET,
    $MAX_SILENCE
) {
    $controlFile = 'audio_control.txt';

    file_put_contents($controlFile, '');

    echo "📁 Arquivo de controle criado: {$controlFile}\n";
    echo "💡 Para parar o servidor, escreva 'STOP' no arquivo {$controlFile}\n";

    go(function () use ($controlFile) {
        while (true) {
            \Swoole\Coroutine::sleep(2);

            if (!file_exists($controlFile)) {
                continue;
            }

            $content = trim(file_get_contents($controlFile));

            if ($content === '') {
                continue;
            }

            if (strtoupper($content) === 'STOP' || strtoupper($content) === 'EXIT') {
                echo "🛑 Comando de parada recebido via arquivo de controle!\n";
                echo "🔄 Encerrando servidor graciosamente...\n";

                file_put_contents($controlFile, '');

                throw new RuntimeException("Shutdown solicitado via arquivo de controle");
            }
        }
    });

    /**
     * UDP listener: recebe PCM vindo da engine RTP/libspech e entrega para browsers.
     */
    go(function () use (
        &$clientInfo,
        $server,
        &$clients,
        &$buffers,
        &$frameQueue,
        &$jitterBuffer,
        &$packetTimestamps,
        &$lastSeen,
        &$lastLog,
        &$lastLogHandshake,
        &$lastLogPeer,
        &$lastLogNoClient,
        &$channelDecode,
        &$udpPeers,
        $BUFFER_TARGET,
        $JITTER_BUFFER_SIZE,
        $FRAME_TARGET,
        $MAX_SILENCE
    ) {
        $udp = new Socket(AF_INET, SOCK_DGRAM, 0);

        if (!$udp->bind("0.0.0.0", 9966)) {
            echo "❌ Falha ao bindar UDP 0.0.0.0:9966\n";
            return;
        }

        echo "🎧 Servidor UDP aguardando pacotes em 9966...\n";

        $lastGC = time();

        while (true) {
            $peer = false;
            $data = $udp->recvfrom($peer, 0.2);

            if (!$data) {
                continue;
            }

            /**
             * Formato:
             * pcm__::__stream/mediaId__::__ssrc__::__portHandle__::__userFrequency__::__frequency
             *
             * Observação: ainda é um protocolo baseado em separador textual sobre binário.
             * Funciona, mas no futuro o ideal é migrar para length-prefix.
             */
            $realData = explode('__::__', $data, 7);

            if (count($realData) < 6) {
                \libspech\Cli\cli::pcl("$peer[address]:$peer[port] invalid data");
                continue;
            }


            [$rtpRaw, $stream, $ssrc, $portHandle, $userFrequencyRaw, $frequencyRaw] = array_slice($realData, 0, 6);
            $sourceChannels = max(1, min(2, (int)($realData[6] ?? 1)));

            $userFrequency = (int)$userFrequencyRaw;
            $frequency = (int)$frequencyRaw;

            if ($stream === '' || $ssrc === '' || $frequency <= 0 || $userFrequency <= 0) {
                echo "⚠️ Stream/frequência inválida no pacote UDP\n";
                continue;
            }

            $frameTarget = strlen($rtpRaw);

            if ($frameTarget <= 0) {
                continue;
            }
            $lastSeen[$stream][$ssrc] ??= time();
            $peerKey = "{$peer['address']}:{$peer['port']}";

            // Log a cada 5 segundos para evitar spam
            if (time() % 5 === 0 && !isset($lastLog[$stream][$ssrc][time()])) {
                $lastLog[$stream][$ssrc][time()] = true;
                cli::pcl("[{$lastSeen[$stream][$ssrc]}] UDP: {$peerKey} {$stream}/{$ssrc} {$frequency}Hz {$userFrequency}Hz", 'green');
            }


            /**
             * Handshake para descobrir peer UDP reverso.
             */
            if (empty($peer['address']) && empty($peer['port']) && !empty($portHandle)) {
                $peerKey = "{$portHandle}";

                if (time() % 5 === 0 && !isset($lastLogHandshake[$stream][$ssrc][time()])) {
                    $lastLogHandshake[$stream][$ssrc][time()] = true;
                    cli::pcl("[{$lastSeen[$stream][$ssrc]}] UDP Handshake: {$peerKey} {$stream}/{$ssrc} {$frequency}Hz {$userFrequency}Hz", 'yellow');
                }

                $udp->sendto('127.0.0.1', (int)$portHandle, SOCKET_EREMOTE);
                $udp->recvfrom($peer, 1);
            }

            if (!empty($peer['address']) && !empty($peer['port'])) {
                $peerKey = "{$peer['address']}:{$peer['port']}";

                if (time() % 5 === 0 && !isset($lastLogPeer[$stream][$ssrc][time()])) {
                    $lastLogPeer[$stream][$ssrc][time()] = true;
                    cli::pcl("[{$lastSeen[$stream][$ssrc]}] UDP Peer: {$peerKey} {$stream}/{$ssrc} {$frequency}Hz {$userFrequency}Hz", 'yellow');
                }

                $udpPeers[$stream] ??= [];
                $udpPeers[$stream][$peerKey] = [
                    'host' => $peer['address'],
                    'port' => (int)$peer['port'],
                ];
            }

            /**
             * Se não tem browser ouvindo essa stream, não acumula buffer à toa.
             */
            if (empty($clients[$stream])) {
                if (time() % 10 === 0 && !isset($lastLogNoClient[$stream][$ssrc][time()])) {
                    $lastLogNoClient[$stream][$ssrc][time()] = true;
                    cli::pcl("[{$lastSeen[$stream][$ssrc]}] UDP No Client: {$peerKey} {$stream}/{$ssrc} {$frequency}Hz {$userFrequency}Hz", 'red');
                }

                unset(
                    $buffers[$stream],
                    $frameQueue[$stream],
                    $jitterBuffer[$stream],
                    $packetTimestamps[$stream]
                );

                continue;
            }

            $lastSeen[$stream][$ssrc] = time();

            /**
             * Jitter buffer simples.
             */
            $jitterBuffer[$stream][$ssrc] ??= [];
            $packetTimestamps[$stream][$ssrc] ??= 0;

            $jitterBuffer[$stream][$ssrc][] = [
                'data' => $rtpRaw,
                'timestamp' => microtime(true),
            ];

            if (count($jitterBuffer[$stream][$ssrc]) > $JITTER_BUFFER_SIZE + 10) {
                array_shift($jitterBuffer[$stream][$ssrc]);
            }

            if (count($jitterBuffer[$stream][$ssrc]) < $JITTER_BUFFER_SIZE) {
                continue;
            }

            usort($jitterBuffer[$stream][$ssrc], static function ($a, $b) {
                return $a['timestamp'] <=> $b['timestamp'];
            });

            $packet = array_shift($jitterBuffer[$stream][$ssrc]);
            $decoded = $packet['data'];

            $buffers[$stream][$ssrc] ??= '';
            $buffers[$stream][$ssrc] .= $decoded;

            /**
             * Limita buffer para evitar latência acumulada.
             */
            if (strlen($buffers[$stream][$ssrc]) > $frequency * 16) {
                $buffers[$stream][$ssrc] = substr($buffers[$stream][$ssrc], -($frequency * 8));
            }

            $validChunks = [];

            foreach ($buffers[$stream] as $source => $buf) {
                if (strlen($buf) >= $frameTarget) {
                    $validChunks[$source] = substr($buf, 0, $frameTarget);
                }
            }

            $activeSources = [];
            $now = time();

            foreach ($lastSeen[$stream] ?? [] as $src => $ts) {
                if ($now - $ts < 2) {
                    $activeSources[] = $src;
                }
            }

            $mixed = null;

            if (count($activeSources) === 1 && count($validChunks) >= 1) {
                $only = array_key_first($validChunks);

                $mixed = $validChunks[$only];
                $buffers[$stream][$only] = substr($buffers[$stream][$only], $frameTarget);
            } elseif (count($activeSources) >= 2 && count($validChunks) >= 2) {
                foreach ($validChunks as $src => $_chunk) {
                    $buffers[$stream][$src] = substr($buffers[$stream][$src], $frameTarget);
                }

                $mixed = mixAudioChannels($validChunks, $userFrequency);
            }

            if ($mixed !== null && strlen($mixed) > 0) {
                $frameQueue[$stream][] = $mixed;

                if (count($frameQueue[$stream]) >= $BUFFER_TARGET) {
                    $sendData = implode('', $frameQueue[$stream]);
                    $frameQueue[$stream] = [];

                    $outData = $sendData;

                    if ($frequency !== $userFrequency) {
                        $outData = resamplePcmChannels($sendData, $frequency, $userFrequency, $sourceChannels);
                    }

                    if ($outData !== '') {
                        foreach ($clients[$stream] ?? [] as $fd) {
                            $targetSsrc = $clientInfo[$fd]['ssrc'] ?? '';
                            $sampleRateDest = $clientInfo[$fd]['sampleRate'] ?? 8000;
                            $targetChannels = max(1, min(2, (int)($clientInfo[$fd]['channels'] ?? 1)));
                            $clientOut = resamplePcmChannels($outData, $userFrequency, $sampleRateDest, $sourceChannels);
                            if ($sourceChannels === 2 && $targetChannels === 1) {
                                $clientOut = \libspech\Sip\stereoToMono($clientOut);
                            }




                            /**
                             * Não envia áudio recebido da engine para conexões de microfone.
                             * Só envia para receivers.
                             */
                            if (str_starts_with($targetSsrc, 'mic-')) {
                                continue;
                            }

                            if (method_exists($server, 'isEstablished') && !$server->isEstablished($fd)) {
                                continue;
                            }



                            $server->push($fd, $clientOut, SWOOLE_WEBSOCKET_OPCODE_BINARY);
                        }
                    }
                }
            }

            /**
             * GC simples.
             */
            if (time() - $lastGC > 8) {
                $current = time();

                foreach ($lastSeen as $streamId => $ssrcs) {
                    if (empty($clients[$streamId])) {
                        unset(
                            $lastSeen[$streamId],
                            $lastLog[$streamId],
                            $lastLogHandshake[$streamId],
                            $lastLogPeer[$streamId],
                            $lastLogNoClient[$streamId],
                            $buffers[$streamId],
                            $frameQueue[$streamId],
                            $jitterBuffer[$streamId],
                            $packetTimestamps[$streamId]
                        );

                        foreach ($ssrcs as $s => $_) {
                            unset($channelDecode[$s]);
                        }

                        echo "🗑️ Removida stream inativa: {$streamId}\n";
                        continue;
                    }

                    foreach ($ssrcs as $sourceSsrc => $ts) {
                        if ($current - $ts > $MAX_SILENCE) {
                            unset(
                                $channelDecode[$sourceSsrc],
                                $lastSeen[$streamId][$sourceSsrc],
                                $buffers[$streamId][$sourceSsrc],
                                $jitterBuffer[$streamId][$sourceSsrc],
                                $packetTimestamps[$streamId][$sourceSsrc]
                            );

                            echo "🗑️ Removido canal inativo: {$streamId}/{$sourceSsrc}\n";
                        }
                    }

                    if (empty($lastSeen[$streamId])) {
                        unset(
                            $lastSeen[$streamId],
                            $lastLog[$streamId],
                            $lastLogHandshake[$streamId],
                            $lastLogPeer[$streamId],
                            $lastLogNoClient[$streamId],
                            $buffers[$streamId],
                            $frameQueue[$streamId],
                            $jitterBuffer[$streamId],
                            $packetTimestamps[$streamId]
                        );
                    }
                }

                $lastGC = time();

                gc_collect_cycles();
            }
        }
    });
});

/**
 * Endpoint para consulta de DTMF keys e página de exemplo.
 */
$server->on("request", function (Request $req, Response $res) use (&$streamKeys) {
    $res->header("Access-Control-Allow-Origin", "*");

    if (($req->server["request_uri"] ?? '') === "/streamKeys") {
        $fp = $req->get["fp"] ?? null;

        if (!$fp) {
            $res->status(400);
            $res->end(json_encode(["error" => "fp required"]));
            return;
        }

        $endpointId = $req->get["endpointId"] ?? null;

        $res->header("Content-Type", "application/json");

        if ($endpointId) {
            $res->end(json_encode([
                "fp" => $fp,
                "endpointId" => $endpointId,
                "keys" => $streamKeys[$fp][$endpointId] ?? "",
            ]));
            return;
        }

        $res->end(json_encode([
            "fp" => $fp,
            "endpoints" => $streamKeys[$fp] ?? [],
        ]));

        return;
    }

    $res->status(404);
    $res->end("Not Found");
});

/**
 * WebSocket open: cliente conecta para receber/enviar áudio ao vivo.
 *
 * Novo:
 *   ?stream={mediaId}
 *
 * Compatibilidade:
 *   ?fp={mediaIdOuCallId}
 */
$server->on("open", function (Server $server, Request $req) use (
    &$clients,
    &$clientInfo
) {
    $stream = $req->get["stream"] ?? $req->get["fp"] ?? null;

    if (!$stream) {
        echo "⚠️ Conexão de áudio sem stream/fp - FD: {$req->fd}\n";
        $server->close($req->fd);
        return;
    }

    $ssrc = $req->get["ssrc"] ?? "ws-{$req->fd}";
    $sampleRate = $req->get["sampleRate"] ?? 8000;
    $channels = max(1, min(2, (int)($req->get['channels'] ?? 1)));
    $ptime = (int)($req->get['ptime'] ?? 20);
    if (!in_array($ptime, \helpers\utils\OpusConfig::ALLOWED_PACKET_TIMES, true)) $ptime = 20;
    $frameMs = (int)($req->get['frameMs'] ?? MicUplinkFrame::FRAME_MS);
    $expectedFrameMs = $ptime === 10 ? 10 : MicUplinkFrame::FRAME_MS;
    if ($frameMs !== $expectedFrameMs) $frameMs = $expectedFrameMs;

    $clients[$stream] ??= [];
    $clients[$stream][$req->fd] = $req->fd;

    $clientInfo[$req->fd] = [
        /**
         * stream é a chave real do audio.php.
         * callId fica por compatibilidade com o código antigo.
         */
        'stream' => $stream,
        'callId' => $stream,
        'ssrc' => $ssrc,
        'sampleRate' => (int)$sampleRate,
        'channels' => $channels,
        'ptime' => $ptime,
        'frameMs' => $frameMs,
        'micPacerStarted' => false,
    ];

    echo "🎧 Cliente áudio {$sampleRate}Hz/{$channels}ch frame={$frameMs}ms ptime={$ptime}ms conectado stream={$stream}, fd={$req->fd}, ssrc={$ssrc}\n";
});

/**
 * WebSocket message: recebe PCM do browser.
 */
$server->on("message", function (Server $server, Frame $frame) use (
    &$udpSendSockets,
    &$clientInfo,
    &$udpPeers,
    &$clients,
    &$micUplinkSessions,
    $MIC_JITTER_TARGET_MS,
    $MIC_MAX_FRAME_AGE_MS
) {
    $fd = $frame->fd;

    if (!isset($clientInfo[$fd])) {
        echo "⚠️ Conexão sem info registrada - FD: {$fd}\n";
        return;
    }

    if ($frame->opcode !== SWOOLE_WEBSOCKET_OPCODE_BINARY) {
        if ($frame->opcode === SWOOLE_WEBSOCKET_OPCODE_TEXT && isset($micUplinkSessions[$fd])) {
            $control = json_decode($frame->data, true);
            if (is_array($control) && ($control['type'] ?? '') === 'micMetrics' && is_array($control['data'] ?? null)) {
                $micUplinkSessions[$fd]->metrics->mergeBrowser($control['data']);
            }
        }
        return;
    }

    if ($frame->data === '') {
        return;
    }

    $stream = $clientInfo[$fd]['stream'] ?? $clientInfo[$fd]['callId'] ?? null;
    $ssrc = $clientInfo[$fd]['ssrc'] ?? "ws-{$fd}";
    $sampleRate = $clientInfo[$fd]['sampleRate'] ?? 8000;
    $channels = max(1, min(2, (int)($clientInfo[$fd]['channels'] ?? 1)));
    $frameMs = (int)($clientInfo[$fd]['frameMs'] ?? MicUplinkFrame::FRAME_MS);

    if (!$stream) {
        echo "⚠️ Frame sem stream vinculada - FD: {$fd}\n";
        return;
    }

    $arrivalMs = micMonotonicMs();
    $micUplinkSessions[$fd] ??= new MicUplinkSession(
        $fd,
        $stream,
        $ssrc,
        (int)$sampleRate,
        $MIC_JITTER_TARGET_MS,
        $MIC_MAX_FRAME_AGE_MS,
        $channels,
        $frameMs,
    );
    $session = $micUplinkSessions[$fd];
    $micFrame = MicUplinkFrame::decode($frame->data, (int)$sampleRate, (int)$arrivalMs);
    if ($micFrame === null) {
        $session->metrics->invalidFrames++;
        return;
    }
    if ($micFrame->channels() !== $session->channels) {
        $session->metrics->invalidFrames++;
        return;
    }
    if ($micFrame->frameMs() !== $session->frameMs) {
        $session->metrics->invalidFrames++;
        return;
    }

    $session->ingest($micFrame, $arrivalMs);
    if (!($clientInfo[$fd]['micPacerStarted'] ?? false)) {
        $clientInfo[$fd]['micPacerStarted'] = true;
        startMicUplinkPacer(
            $server,
            $session,
            $udpPeers,
            $udpSendSockets,
            $clients,
            $clientInfo
        );
    }
});

/**
 * Close: remove cliente e destrói UDP socket associado ao FD.
 */
$server->on("close", function ($serv, int $fd) use (
    &$udpSendSockets,
    &$clients,
    &$buffers,
    &$clientInfo,
    &$frameQueue,
    &$jitterBuffer,
    &$packetTimestamps,
    &$lastSeen,
    &$lastLog,
    &$lastLogHandshake,
    &$lastLogPeer,
    &$lastLogNoClient,
    &$udpPeers,
    &$micUplinkSessions
) {
    if (isset($micUplinkSessions[$fd])) {
        $micUplinkSessions[$fd]->close();
        unset($micUplinkSessions[$fd]);
    }
    closeUdpSendSocketForFd($fd, $udpSendSockets);

    $stream = $clientInfo[$fd]['stream'] ?? $clientInfo[$fd]['callId'] ?? null;

    if ($stream !== null && isset($clients[$stream][$fd])) {
        unset($clients[$stream][$fd]);

        echo "❌ Cliente saiu stream={$stream}, fd={$fd}\n";

        /**
         * Só limpa buffers da stream se não sobrou ninguém ouvindo.
         * Antes o código limpava buffers mesmo com outro cliente ativo.
         */
        if (empty($clients[$stream])) {
            unset(
                $clients[$stream],
                $buffers[$stream],
                $frameQueue[$stream],
                $jitterBuffer[$stream],
                $packetTimestamps[$stream],
                $lastSeen[$stream],
                $lastLog[$stream],
                $lastLogHandshake[$stream],
                $lastLogPeer[$stream],
                $lastLogNoClient[$stream],
                $udpPeers[$stream]
            );

            echo "🧹 Stream finalizada e limpa: {$stream}\n";
        }
    } else {
        /**
         * Fallback caso clientInfo tenha sumido antes.
         */
        foreach ($clients as $streamId => &$list) {
            if (!isset($list[$fd])) {
                continue;
            }

            unset($list[$fd]);

            echo "❌ Cliente saiu stream={$streamId}, fd={$fd}\n";

            if (empty($list)) {
                unset(
                    $clients[$streamId],
                    $buffers[$streamId],
                    $frameQueue[$streamId],
                    $jitterBuffer[$streamId],
                    $packetTimestamps[$streamId],
                    $lastSeen[$streamId],
                    $lastLog[$streamId],
                    $lastLogHandshake[$streamId],
                    $lastLogPeer[$streamId],
                    $lastLogNoClient[$streamId],
                    $udpPeers[$streamId]
                );

                echo "🧹 Stream finalizada e limpa: {$streamId}\n";
            }

            break;
        }

        unset($list);
    }

    unset($clientInfo[$fd]);
});

$server->start();

/**
 * Cabeçalho WAV simples.
 */
function waveHead3(int $dataLength, int $sampleRate, int $channels, int $audioFormat): string
{
    $bitsPerSample = 16;
    $byteRate = $sampleRate * $channels * ($bitsPerSample / 8);
    $blockAlign = $channels * ($bitsPerSample / 8);

    return
        pack('a4V', 'RIFF', 36 + $dataLength) .
        'WAVE' .
        pack(
            'a4VvvVVvv',
            'fmt ',
            16,
            1,
            $channels,
            $sampleRate,
            (int)$byteRate,
            (int)$blockAlign,
            $bitsPerSample
        ) .
        pack('a4V', 'data', $dataLength);
}
