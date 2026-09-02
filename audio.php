<?php

use helpers\utils\AudioIpcPacket;
use helpers\utils\AudioPipelineMetrics;
use helpers\utils\MicUplinkFrame;
use helpers\utils\MicUplinkSession;
use helpers\utils\OpusConfig;
use helpers\utils\PcmProcessor;
use helpers\utils\RealtimeStreamQueue;
use libspech\Cache\cache;
use libspech\Cli\cli;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Runtime;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

Runtime::enableCoroutine();

ini_set('memory_limit', '4024M');

include 'libspech/plugins/autoloader.php';
require_once __DIR__ . '/plugins/Utils/helpers/MicUplinkFrame.php';
require_once __DIR__ . '/plugins/Utils/helpers/MicQualityMetrics.php';
require_once __DIR__ . '/plugins/Utils/helpers/MicJitterBuffer.php';
require_once __DIR__ . '/plugins/Utils/helpers/RtpPacer.php';
require_once __DIR__ . '/plugins/Utils/helpers/MicUplinkSession.php';
require_once __DIR__ . '/plugins/Utils/helpers/OpusConfig.php';
require_once __DIR__ . '/plugins/Utils/helpers/AudioPipelineMetrics.php';
require_once __DIR__ . '/plugins/Utils/helpers/PcmProcessor.php';
require_once __DIR__ . '/plugins/Utils/helpers/AudioIpcPacket.php';
require_once __DIR__ . '/plugins/Utils/helpers/RealtimeStreamQueue.php';

$clients = [];
$clientInfo = [];
$buffers = [];
$lastSeen = [];
$streamKeys = [];
$udpPeers = [];
$udpSendSockets = [];
$micUplinkSessions = [];
$streamQueues = [];
$streamWorkers = [];
$sourceFormats = [];
$pipelineMetrics = [];
$ipcLastReceiveNs = [];
$lastIpcSeenNs = [];

$PLAYBACK_BATCH_MS = max(10, min(60, (int)(getenv('AUDIO_PLAYBACK_BATCH_MS') ?: 20)));
$IPC_QUEUE_MAX_MS = max(40, min(240, (int)(getenv('AUDIO_IPC_QUEUE_MAX_MS') ?: 120)));
$SOURCE_BUFFER_MAX_MS = max(40, min(240, (int)(getenv('AUDIO_SOURCE_BUFFER_MAX_MS') ?: 120)));
$MAX_SILENCE = 30;
$MIC_JITTER_TARGET_MS = max(40, min(100, (int)(getenv('MIC_JITTER_TARGET_MS') ?: 60)));
$MIC_MAX_FRAME_AGE_MS = max(160, min(200, (int)(getenv('MIC_MAX_FRAME_AGE_MS') ?: 180)));

cache::define('rateCall', 8000);

$cfNamefile = 'plugins/configInterface.json';
$configInterface = json_decode(file_get_contents($cfNamefile), true);

/**
 * Configuração do servidor WebSocket de áudio.
 */
$server = new Server("0.0.0.0", 8889, SWOOLE_BASE, SWOOLE_SOCK_TCP | SWOOLE_SSL);

$server->set([
    'ssl_cert_file' => $configInterface['serverSettings']['ssl_cert_file'],
    'ssl_key_file' => $configInterface['serverSettings']['ssl_key_file'],
    'enable_coroutine' => true,
    'open_tcp_nodelay' => false,
    'tcp_fastopen' => true,
    'enable_reuse_port' => false,
    'package_max_length' => 1024 * 1024 * 10,
    'socket_buffer_size' => 1024 * 1024 * 10,

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

/**
 * Envia PCM do browser para os peers UDP/libspech da mesma stream.
 */
function sendBrowserPcmToUdpPeers(
    int    $fd,
    string $stream,
    string $ssrc,
    string $pcm,
    int $sampleRate,
    int $channels,
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
        $packet = new AudioIpcPacket($pcm, $stream, $ssrc, $sampleRate, $channels);
        $udp->sendto($peerInfo['host'], (int)$peerInfo['port'], $packet->encode());
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
    int $sourceRate,
    int $sourceChannels,
    array  &$clients,
    array  &$clientInfo,
    array  &$pipelineMetrics,
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

        $targetRate = (int)($clientInfo[$targetFd]['sampleRate'] ?? $sourceRate);
        $targetChannels = max(1, min(2, (int)($clientInfo[$targetFd]['channels'] ?? 1)));
        $metrics = $pipelineMetrics[$stream] ??= new AudioPipelineMetrics();
        try {
            $clientPcm = PcmProcessor::convert(
                $pcmData,
                $sourceRate,
                $sourceChannels,
                $targetRate,
                $targetChannels,
                $metrics,
            );
        } catch (Throwable) {
            continue;
        }
        $server->push($targetFd, $clientPcm, SWOOLE_WEBSOCKET_OPCODE_BINARY);
    }
}

/** Converts and pushes every ready source directly to each browser format. */
function flushPlaybackFrames(
    Server $server,
    string $stream,
    int $batchMs,
    array &$clients,
    array &$clientInfo,
    array &$buffers,
    array &$sourceFormats,
    AudioPipelineMetrics $metrics,
): void {
    while (true) {
        $chunks = [];
        foreach ($buffers[$stream] ?? [] as $source => $buffer) {
            $format = $sourceFormats[$stream][$source] ?? null;
            if (!is_array($format)) continue;
            $chunkBytes = PcmProcessor::bytesForDuration($format['rate'], $format['channels'], $batchMs);
            if (strlen($buffer) < $chunkBytes) continue;
            $chunks[$source] = [
                'pcm' => substr($buffer, 0, $chunkBytes),
                'rate' => $format['rate'],
                'channels' => $format['channels'],
            ];
        }
        if ($chunks === []) return;

        foreach ($chunks as $source => $chunk) {
            $buffers[$stream][$source] = substr($buffers[$stream][$source], strlen($chunk['pcm']));
        }

        foreach ($clients[$stream] ?? [] as $fd) {
            $targetSsrc = $clientInfo[$fd]['ssrc'] ?? '';
            if (str_starts_with($targetSsrc, 'mic-')) continue;
            if (method_exists($server, 'isEstablished') && !$server->isEstablished($fd)) continue;

            $targetRate = max(1, (int)($clientInfo[$fd]['sampleRate'] ?? 8000));
            $targetChannels = max(1, min(2, (int)($clientInfo[$fd]['channels'] ?? 1)));
            $converted = [];
            foreach ($chunks as $chunk) {
                try {
                    $converted[] = PcmProcessor::convert(
                        $chunk['pcm'],
                        $chunk['rate'],
                        $chunk['channels'],
                        $targetRate,
                        $targetChannels,
                        $metrics,
                    );
                } catch (Throwable) {
                    continue;
                }
            }
            $output = PcmProcessor::mix($converted);
            if ($output !== '') $server->push($fd, $output, SWOOLE_WEBSOCKET_OPCODE_BINARY);
        }
    }
}

/** Starts exactly one persistent playback worker for an active stream. */
function startPlaybackStreamWorker(
    Server $server,
    string $stream,
    RealtimeStreamQueue $queue,
    int $batchMs,
    int $sourceBufferMaxMs,
    array &$streamQueues,
    array &$streamWorkers,
    array &$clients,
    array &$clientInfo,
    array &$buffers,
    array &$sourceFormats,
    array &$lastSeen,
    array &$pipelineMetrics,
): void {
    $workerId = spl_object_id($queue);
    $streamWorkers[$stream] = $workerId;
    Coroutine::create(function () use (
        $server,
        $stream,
        $queue,
        $workerId,
        $batchMs,
        $sourceBufferMaxMs,
        &$streamQueues,
        &$streamWorkers,
        &$clients,
        &$clientInfo,
        &$buffers,
        &$sourceFormats,
        &$lastSeen,
        &$pipelineMetrics,
    ): void {
        try {
            while ($queue->isActive()) {
                $item = $queue->dequeue();
                if ($item === null) {
                    Coroutine::sleep(0.001);
                    continue;
                }
                if (empty($clients[$stream])) {
                    $queue->close();
                    break;
                }

                $packet = $item['packet'] ?? null;
                if (!$packet instanceof AudioIpcPacket) continue;
                $metrics = $pipelineMetrics[$stream] ??= new AudioPipelineMetrics();
                $startedNs = hrtime(true);
                $metrics->recordQueueDelay(($startedNs - (int)$item['enqueuedAtNs']) / 1000);

                $source = $packet->source;
                $previous = $sourceFormats[$stream][$source] ?? null;
                if (is_array($previous)
                    && ($previous['rate'] !== $packet->sampleRate || $previous['channels'] !== $packet->channels)) {
                    $buffers[$stream][$source] = '';
                }
                $sourceFormats[$stream][$source] = ['rate' => $packet->sampleRate, 'channels' => $packet->channels];
                $lastSeen[$stream][$source] = microtime(true);
                $buffers[$stream][$source] = ($buffers[$stream][$source] ?? '') . $packet->payload;

                $maximumBytes = PcmProcessor::bytesForDuration(
                    $packet->sampleRate,
                    $packet->channels,
                    $sourceBufferMaxMs,
                );
                if (strlen($buffers[$stream][$source]) > $maximumBytes) {
                    $dropBytes = strlen($buffers[$stream][$source]) - $maximumBytes;
                    $alignment = 2 * $packet->channels;
                    $dropBytes -= $dropBytes % $alignment;
                    $buffers[$stream][$source] = substr($buffers[$stream][$source], $dropBytes);
                    $metrics->recordQueue(0, 1);
                }

                flushPlaybackFrames(
                    $server,
                    $stream,
                    $batchMs,
                    $clients,
                    $clientInfo,
                    $buffers,
                    $sourceFormats,
                    $metrics,
                );
                $metrics->recordStreamProcessing((hrtime(true) - $startedNs) / 1000);
            }
        } finally {
            if (($streamWorkers[$stream] ?? null) === $workerId) {
                unset($streamWorkers[$stream], $streamQueues[$stream], $buffers[$stream], $sourceFormats[$stream]);
            }
        }
    });
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
    Server           $server,
    MicUplinkSession $session,
    array            &$udpPeers,
    array            &$udpSendSockets,
    array            &$clients,
    array            &$clientInfo,
    array            &$pipelineMetrics,
): void
{
    Coroutine::create(function () use (
        $server,
        $session,
        &$udpPeers,
        &$udpSendSockets,
        &$clients,
        &$clientInfo,
        &$pipelineMetrics,
    ): void {
        while ($session->active) {
            $nowMs = micMonotonicMs();
            if (!$session->startIfReady($nowMs)) {
                Coroutine::sleep(0.005);
                continue;
            }

            $deadline = $session->pacer->deadlineMs();
            if ($deadline !== null && $nowMs < $deadline) {
                Coroutine::sleep(max(0.001, min(0.02, ($deadline - $nowMs) / 1000)));
                continue;
            }

            $pcmData = $session->tick($nowMs);
            if ($pcmData !== null) {
                sendBrowserPcmToUdpPeers(
                    $session->fd,
                    $session->stream,
                    $session->ssrc,
                    $pcmData,
                    $session->sampleRate,
                    $session->channels,
                    $udpPeers,
                    $udpSendSockets
                );
                relayBrowserToBrowser(
                    $server,
                    $session->fd,
                    $session->stream,
                    $session->ssrc,
                    $pcmData,
                    $session->sampleRate,
                    $session->channels,
                    $clients,
                    $clientInfo,
                    $pipelineMetrics,
                );
            }

            if ($nowMs - $session->lastMetricsAtMs >= 1000) {
                $snapshot = $session->snapshot($nowMs);
                if (isset($clientInfo[$session->fd])
                    && (!method_exists($server, 'isEstablished') || $server->isEstablished($session->fd))) {
                    $server->push($session->fd, json_encode([
                        'type' => 'micQuality',
                        'data' => $snapshot,
                    ], JSON_UNESCAPED_SLASHES));
                }
                $session->lastMetricsAtMs = $nowMs;

                if ($nowMs - $session->lastLogAtMs >= 10000) {
                    $wsKb = round(($snapshot['wsBufferedAmount'] ?? 0) / 1024, 1);
                    cli::pcl(
                        "[MIC:QUALITY] callId={$session->stream} quality={$snapshot['quality']} "
                        . "jitter={$snapshot['recentJitterP95']}ms queue="
                        . ($snapshot['browserQueueMs'] ?? 0) . "ms recentDrops="
                        . round($snapshot['recentDropPercent'], 1) . "% totalDrops="
                        . round($snapshot['totalDropPercent'], 1) . "% "
                        . "wsBuffered={$wsKb}KB recentUnderruns={$snapshot['recentUnderruns']}("
                        . round($snapshot['recentUnderrunPercent'], 1) . "%) "
                        . "pacerUnderruns={$snapshot['pacerUnderruns']} "
                        . "pacerP95={$snapshot['rtpPacingGapP95']}ms",
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
    &$streamKeys,
    &$lastSeen,
    &$streamQueues,
    &$streamWorkers,
    &$sourceFormats,
    &$pipelineMetrics,
    &$ipcLastReceiveNs,
    &$lastIpcSeenNs,
    $PLAYBACK_BATCH_MS,
    $IPC_QUEUE_MAX_MS,
    $SOURCE_BUFFER_MAX_MS,
    $MAX_SILENCE
) {
    $controlFile = 'audio_control.txt';

    file_put_contents($controlFile, '');

    echo "📁 Arquivo de controle criado: {$controlFile}\n";
    echo "💡 Para parar o servidor, escreva 'STOP' no arquivo {$controlFile}\n";

    go(function () use ($controlFile) {
        while (true) {
            Coroutine::sleep(2);

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

    // Diagnostics and lifecycle cleanup stay outside the recvfrom hot path.
    go(function () use (
        &$pipelineMetrics,
        &$streamQueues,
        &$streamWorkers,
        &$lastIpcSeenNs,
        &$ipcLastReceiveNs,
        &$udpPeers,
        &$clients,
        $MAX_SILENCE,
    ): void {
        while (true) {
            Coroutine::sleep(10);
            $nowNs = hrtime(true);
            foreach ($pipelineMetrics as $stream => $metrics) {
                $queueDepth = isset($streamQueues[$stream]) ? $streamQueues[$stream]->depth() : 0;
                cli::pcl('[AUDIO:PIPELINE] stream=' . $stream . ' '
                    . json_encode($metrics->snapshot($queueDepth), JSON_UNESCAPED_SLASHES), 'cyan');

                $silentSeconds = ($nowNs - (int)($lastIpcSeenNs[$stream] ?? $nowNs)) / 1_000_000_000;
                if (empty($clients[$stream]) && $silentSeconds > $MAX_SILENCE) {
                    if (isset($streamQueues[$stream])) $streamQueues[$stream]->close();
                    unset(
                        $pipelineMetrics[$stream],
                        $streamWorkers[$stream],
                        $lastIpcSeenNs[$stream],
                        $ipcLastReceiveNs[$stream],
                        $udpPeers[$stream]
                    );
                }
            }
            gc_collect_cycles();
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
        &$lastSeen,
        &$udpPeers,
        &$streamQueues,
        &$streamWorkers,
        &$sourceFormats,
        &$pipelineMetrics,
        &$ipcLastReceiveNs,
        &$lastIpcSeenNs,
        $PLAYBACK_BATCH_MS,
        $IPC_QUEUE_MAX_MS,
        $SOURCE_BUFFER_MAX_MS,
    ) {
        $udp = new Socket(AF_INET, SOCK_DGRAM, 0);

        if (!$udp->bind("127.0.0.1", 9966)) {
            echo "❌ Falha ao bindar UDP 127.0.0.1:9966\n";
            return;
        }

        echo "🎧 Servidor UDP aguardando pacotes em 9966...\n";

        while (true) {
            $peer = false;
            $data = $udp->recvfrom($peer, 0.2);

            if (!$data) {
                continue;
            }

            $processingStartedNs = hrtime(true);
            // Binary v1 is collision-safe. Legacy packets remain readable during rollout.
            $packet = AudioIpcPacket::decode($data) ?? AudioIpcPacket::decodeLegacyPlayback($data);
            if (!$packet instanceof AudioIpcPacket) continue;

            $stream = $packet->stream;
            $ssrc = $packet->source;
            $metrics = $pipelineMetrics[$stream] ??= new AudioPipelineMetrics();
            $gapUs = isset($ipcLastReceiveNs[$stream])
                ? ($processingStartedNs - $ipcLastReceiveNs[$stream]) / 1000
                : null;
            $latencyUs = $packet->sentAtNs > 0
                ? max(0, ($processingStartedNs - $packet->sentAtNs) / 1000)
                : null;
            $metrics->recordIpcPacket(strlen($data), $gapUs, $latencyUs);
            $ipcLastReceiveNs[$stream] = $processingStartedNs;
            $lastIpcSeenNs[$stream] = $processingStartedNs;
            $peerKey = "{$peer['address']}:{$peer['port']}";

            if (!empty($peer['address']) && !empty($peer['port'])) {
                $peerKey = "{$peer['address']}:{$peer['port']}";
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
                $metrics->recordIpcProcessing((hrtime(true) - $processingStartedNs) / 1000);
                continue;
            }

            $durationMs = PcmProcessor::durationMs(
                $packet->payload,
                $packet->sampleRate,
                $packet->channels,
            );
            if ($durationMs <= 0) continue;

            if (!isset($streamQueues[$stream]) || !$streamQueues[$stream]->isActive()) {
                $streamQueues[$stream] = new RealtimeStreamQueue((float)$IPC_QUEUE_MAX_MS, 24);
            }
            $queue = $streamQueues[$stream];
            $drops = $queue->enqueue([
                'packet' => $packet,
                'enqueuedAtNs' => hrtime(true),
            ], $durationMs);
            $metrics->recordQueue($queue->depth(), $drops);

            if (!isset($streamWorkers[$stream])) {
                startPlaybackStreamWorker(
                    $server,
                    $stream,
                    $queue,
                    $PLAYBACK_BATCH_MS,
                    $SOURCE_BUFFER_MAX_MS,
                    $streamQueues,
                    $streamWorkers,
                    $clients,
                    $clientInfo,
                    $buffers,
                    $sourceFormats,
                    $lastSeen,
                    $pipelineMetrics,
                );
            }
            $metrics->recordIpcProcessing((hrtime(true) - $processingStartedNs) / 1000);
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
    if (!in_array($ptime, OpusConfig::ALLOWED_PACKET_TIMES, true)) $ptime = 20;
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
    &$pipelineMetrics,
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
            $clientInfo,
            $pipelineMetrics,
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
    &$lastSeen,
    &$udpPeers,
    &$micUplinkSessions,
    &$streamQueues,
    &$streamWorkers,
    &$sourceFormats,
    &$pipelineMetrics,
    &$ipcLastReceiveNs,
    &$lastIpcSeenNs,
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
            if (isset($streamQueues[$stream])) $streamQueues[$stream]->close();
            unset(
                $clients[$stream],
                $buffers[$stream],
                $lastSeen[$stream],
                $udpPeers[$stream],
                $streamQueues[$stream],
                $streamWorkers[$stream],
                $sourceFormats[$stream],
                $pipelineMetrics[$stream],
                $ipcLastReceiveNs[$stream],
                $lastIpcSeenNs[$stream]
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
                if (isset($streamQueues[$streamId])) $streamQueues[$streamId]->close();
                unset(
                    $clients[$streamId],
                    $buffers[$streamId],
                    $lastSeen[$streamId],
                    $udpPeers[$streamId],
                    $streamQueues[$streamId],
                    $streamWorkers[$streamId],
                    $sourceFormats[$streamId],
                    $pipelineMetrics[$streamId],
                    $ipcLastReceiveNs[$streamId],
                    $lastIpcSeenNs[$streamId]
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
