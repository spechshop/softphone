<?php

use libspech\Cache\cache;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

\Swoole\Runtime::enableCoroutine();
ini_set('memory_limit', '4024M');
include 'libspech/plugins/autoloader.php';
$clients = [];
$clientInfo = [];
$buffers = [];
$lastSeen = [];
$frameQueue = [];
$jitterBuffer = []; // Buffer para absorver variação de latência
$packetTimestamps = []; // Timestamps dos pacotes para ordenação
$streamKeys = [];
$udpPeers = [];
$BUFFER_TARGET = 6; // Aumentado para 6 - máxima resiliência em rede fraca
$JITTER_BUFFER_SIZE = 5; // Aumentado para 5 pacotes extras para compensar jitter
$FRAME_TARGET = 320;
$MAX_SILENCE = 30;
/**
 * Configuração do servidor WebSocket
 */
$server = new Server("0.0.0.0", 8888, SWOOLE_BASE, SWOOLE_SOCK_TCP | SWOOLE_SSL);
$server->set([
    'ssl_cert_file' => 'fullchain.pem',
    'ssl_key_file' => 'privkey.pem'
]);

$channelDecode = [];
cache::define('rateCall', 8000);
$server->on("start", function (Server $server) use (&$clientInfo, &$clients, &$udpPeers, &$buffers, &$frameQueue, &$jitterBuffer, &$packetTimestamps, &$streamKeys, &$lastSeen, &$channelDecode, $BUFFER_TARGET, $JITTER_BUFFER_SIZE, $FRAME_TARGET, $MAX_SILENCE) {
    $controlFile = 'audio_control.txt';
    file_put_contents($controlFile, '');
    echo "📁 Arquivo de controle criado: {$controlFile}\n";
    echo "💡 Para parar o servidor, escreva 'STOP' no arquivo {$controlFile}\n";
    go(function () use ($controlFile) {
        while (true) {
            \Swoole\Coroutine::sleep(2);
            if (file_exists($controlFile)) {
                $content = trim(file_get_contents($controlFile));
                if (!empty($content) && (strtoupper($content) === 'STOP' || strtoupper($content) === 'EXIT')) {
                    echo "🛑 Comando de parada recebido via arquivo de controle!\n";
                    echo "🔄 Encerrando servidor graciosamente...\n";
                    file_put_contents($controlFile, '');
                    throw new \Exception("Shutdown solicitado via arquivo de controle");
                }
            }
        }
    });
    go(function () use (&$clientInfo, $server, &$clients, &$buffers, &$frameQueue, &$jitterBuffer, &$packetTimestamps, &$lastSeen, &$channelDecode, &$udpPeers, $BUFFER_TARGET, $JITTER_BUFFER_SIZE, $FRAME_TARGET, $MAX_SILENCE) {
        $udp = new Swoole\Coroutine\Socket(AF_INET, SOCK_DGRAM, 0);
        $udp->bind("0.0.0.0", 9600);
        echo "🎧 Servidor UDP aguardando pacotes em 9600...\n";
        $lastGC = time();
        while (true) {
            $peer = false;
            $data = $udp->recvfrom($peer, 0.2);
            if (!$data) {
                continue;
            }
            $realData = explode('__::__', $data);
            if (count($realData) < 3) {
                continue;
            }
            [$rtpRaw, $callId, $ssrc, $portHandle, $userFrequency, $frequency] = $realData;
            if (empty($frequency) || empty($userFrequency)) {
                echo "⚠️ Codec ou frequência inválidos: {$data}\n";
                continue;
            }
            $FRAME_TARGET = strlen($rtpRaw);
            if (empty($peer['address']) && empty($peer['port'])) {
                $udp->sendto('127.0.0.1', $portHandle, SOCKET_EREMOTE);
                $res = $udp->recvfrom($peer, 1);
            }
            if (!empty($peer['address']) && !empty($peer['port'])) {
                $udpPeers[$callId] ??= [];
                $udpPeers[$callId]["{$peer['address']}{$peer['port']}"] = [
                    'host' => $peer['address'],
                    'port' => $peer['port'],
                ];
            }
            if (empty($clients[$callId])) {
                unset($buffers[$callId], $frameQueue[$callId]);
                continue;
            }
            $lastSeen[$callId][$ssrc] = time();
            $decoded = $rtpRaw;

            // Jitter buffer: armazena pacotes com timestamp
            $jitterBuffer[$callId][$ssrc] ??= [];
            $packetTimestamps[$callId][$ssrc] ??= 0;

            $timestamp = microtime(true);
            $jitterBuffer[$callId][$ssrc][] = [
                'data' => $decoded,
                'timestamp' => $timestamp,
            ];

            // Limita tamanho do jitter buffer
            if (count($jitterBuffer[$callId][$ssrc]) > $JITTER_BUFFER_SIZE + 10) {
                array_shift($jitterBuffer[$callId][$ssrc]);
            }

            // Processa apenas se tiver pacotes suficientes (espera jitter buffer encher)
            if (count($jitterBuffer[$callId][$ssrc]) < $JITTER_BUFFER_SIZE) {
                continue;
            }

            // Ordena por timestamp e pega o mais antigo
            usort($jitterBuffer[$callId][$ssrc], function ($a, $b) {
                return $a['timestamp'] <=> $b['timestamp'];
            });

            $packet = array_shift($jitterBuffer[$callId][$ssrc]);
            $decoded = $packet['data'];

            $buffers[$callId][$ssrc] ??= '';
            $buffers[$callId][$ssrc] .= $decoded;

            // Limita buffer para evitar latência excessiva (aumentado para 16x - máximo)
            if (strlen($buffers[$callId][$ssrc]) > $frequency * 16) {
                $buffers[$callId][$ssrc] = substr($buffers[$callId][$ssrc], -($frequency * 8));
            }
            $validChunks = [];
            foreach ($buffers[$callId] as $source => $buf) {
                if (strlen($buf) >= $FRAME_TARGET) {
                    $validChunks[$source] = substr($buf, 0, $FRAME_TARGET);
                }
            }
            $activeSources = [];
            $now = time();
            foreach ($lastSeen[$callId] ?? [] as $src => $ts) {
                if ($now - $ts < 2) {
                    $activeSources[] = $src;
                }
            }
            $mixed = null;
            if (count($activeSources) === 1 && count($validChunks) >= 1) {
                $only = array_key_first($validChunks);
                $mixed = $validChunks[$only];
                $buffers[$callId][$only] = substr($buffers[$callId][$only], $FRAME_TARGET);
            } elseif (count($activeSources) >= 2 && count($validChunks) >= 2) {
                foreach ($validChunks as $src => $chunk) {
                    $buffers[$callId][$src] = substr($buffers[$callId][$src], $FRAME_TARGET);
                }
                $mixed = mixAudioChannels($validChunks, $userFrequency);

            }
            if ($mixed) {

                $frameQueue[$callId][] = $mixed;

                if (count($frameQueue[$callId]) >= $BUFFER_TARGET) {
                    $sendData = implode('', $frameQueue[$callId]);
                    $frameQueue[$callId] = [];
                    foreach ($clients[$callId] as $fd) {
                        $ssrc = $clientInfo[$fd]['ssrc'];
                        if ($ssrc == 'mic-user') continue;


                        $mixer = resampler($sendData, $frequency, $userFrequency);
                        $sendData = $mixer;

                        if (strlen($mixer) > 0) {
                            // \libspech\Cli\cli::pcl(strlen($sendData) . ' ssrc:' . $ssrc . ' fd:' . $fd . '  ' . $userFrequency);
                            $server->push($fd, $mixer, SWOOLE_WEBSOCKET_OPCODE_BINARY);
                        }

                    }
                }
            }
            // GC mais frequente para liberar memória (reduzido de 15s para 8s)
            if (time() - $lastGC > 8) {
                $current = time();
                foreach ($lastSeen as $cId => $ssrcs) {
                    if (empty($clients[$cId])) {
                        unset($lastSeen[$cId], $buffers[$cId], $frameQueue[$cId], $jitterBuffer[$cId], $packetTimestamps[$cId]);
                        foreach ($ssrcs as $s => $_) {
                            unset($channelDecode[$s]);
                        }
                        echo "🗑️ Removido callId inativo: {$cId}\n";
                        continue;
                    }
                    foreach ($ssrcs as $s => $ts) {
                        if ($current - $ts > $MAX_SILENCE) {
                            unset($channelDecode[$s], $lastSeen[$cId][$s], $buffers[$cId][$s], $jitterBuffer[$cId][$s], $packetTimestamps[$cId][$s]);
                            echo "🗑️ Removido canal inativo: {$cId}/{$s}\n";
                        }
                    }
                    if (empty($lastSeen[$cId])) {
                        unset($lastSeen[$cId], $buffers[$cId], $frameQueue[$cId], $jitterBuffer[$cId], $packetTimestamps[$cId]);
                    }
                }
                $lastGC = time();
                gc_collect_cycles();
            }
        }
    });
});
/**
 *  endpoint para consulta de DTMF keys e página de exemplo
 */
$server->on("request", function (Request $req, Response $res) use (&$streamKeys) {
    $res->header("Access-Control-Allow-Origin", "*");
    if ($req->server["request_uri"] === "/streamKeys") {
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
        } else {
            $res->end(json_encode([
                "fp" => $fp,
                "endpoints" => $streamKeys[$fp] ?? [],
            ]));
        }
        return;
    }
    $res->status(404);
    $res->end("Not Found");
});
/**
 *   WebSocket: cliente conecta para receber áudio ao vivo
 */
$server->on("open", function (Server $server, Request $req) use (&$clients, &$clientInfo) {
    $fp = $req->get["fp"] ?? null;
    if (!$fp) {
        $server->close($req->fd);
        return;
    }
    $ssrc = $req->get["ssrc"] ?? "ws-{$req->fd}";
    if (!isset($clients[$fp])) {
        $clients[$fp] = [];
    }
    $clients[$fp][$req->fd] = $req->fd;
    $clientInfo[$req->fd] = [
        'callId' => $fp,
        'ssrc' => $ssrc,
    ];
});
/**
 * WebSocket: recebe PCM do cliente e encaminha para UDP peers
 * Formato: PCM binário puro (Int16Array)
 */
$server->on("message", function (Server $server, Frame $frame) use (&$clientInfo, &$udpPeers) {


    if (!isset($clientInfo[$frame->fd])) {
        echo "⚠️ Conexão sem info registrada - FD: {$frame->fd}\n";
        return;
    }
    $callId = $clientInfo[$frame->fd]['callId'];
    $ssrc = $clientInfo[$frame->fd]['ssrc'];
    if ($frame->opcode === SWOOLE_WEBSOCKET_OPCODE_BINARY) {
        $pcmData = $frame->data;
        if (strlen($pcmData) === 0) {
            return;
        }
        $packet = $pcmData . '__::__' . $callId . '__::__' . $ssrc;
        if (!empty($udpPeers[$callId])) {
            go(function () use ($packet, $pcmData, $callId, $ssrc, &$udpPeers) {
                $udp = new Swoole\Coroutine\Socket(AF_INET, SOCK_DGRAM, 0);
                foreach ($udpPeers[$callId] as $peerSsrc => $peerInfo) {
                    //\libspech\Cli\cli::pcl("Enviando audio para peer: {$peerSsrc} - SSRC: {$ssrc}", 'bold_green');

                    if ($peerSsrc === $ssrc) {
                        continue;
                    }

                    $udp->sendto($peerInfo['host'], $peerInfo['port'], $packet);
                }
                $udp->close();
            });
        }
    }
});
/**
 * Remove cliente desconectado
 */
$server->on("close", function ($serv, $fd) use (&$clients, &$buffers, &$clientInfo) {
    foreach ($clients as $callId => &$list) {
        if (isset($list[$fd])) {
            unset($list[$fd]);
            echo "❌ Cliente saiu ({$callId})\n";
            if (empty($list)) {
                unset($clients[$callId]);
            }
            unset($buffers[$callId]);
            break;
        }
    }
    if (isset($clientInfo[$fd])) {
        unset($clientInfo[$fd]);
    }
});
$server->start();

/**
 * Cabeçalho WAV simples
 */
function waveHead3(int $dataLength, int $sampleRate, int $channels, int $audioFormat): string
{
    $bitsPerSample = 16;
    $byteRate = $sampleRate * $channels * ($bitsPerSample / 8);
    $blockAlign = $channels * ($bitsPerSample / 8);
    return pack('a4V', 'RIFF', 36 + $dataLength) . 'WAVE' . pack('a4VvvVVvv', 'fmt ', 16, 1, $channels, $sampleRate, (int)$byteRate, (int)$blockAlign, $bitsPerSample) . pack('a4V', 'data', $dataLength);
}