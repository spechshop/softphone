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
// callId => [fd => fd] (WebSocket connections)
$clientInfo = [];
// fd => [callId, ssrc] (Info das conexões)
$buffers = [];
// callId => [ssrc => buffer PCM]
$lastSeen = [];
// callId => [ssrc => timestamp]
$frameQueue = [];
// callId => [frames PCM]
$streamKeys = [];
// callId => [endpointId => DTMF string]
$udpPeers = [];
// callId => [ssrc => [host, port]] (Endereços UDP dos peers)
$BUFFER_TARGET = 1;
// frames por envio (~40ms por pacote)
$FRAME_TARGET = 320;
// 320 bytes (~20ms PCM16 mono por frame)
$MAX_SILENCE = 30;
// segundos até GC de SSRC inativo
/**
 * Configuração do servidor WebSocket
 */
$server = new Server("0.0.0.0", 8888, SWOOLE_BASE, SWOOLE_SOCK_TCP | SWOOLE_SSL);
$server->set([
    'ssl_cert_file' => 'fullchain.pem',
    'ssl_key_file' => 'privkey.pem'
]);
/**
 * 🔥 Listener UDP: recebe pacotes PCM decodificados
 */
$channelDecode = [];
// Mover para escopo global
cache::define('rateCall', 8000);
$server->on("start", function (Server $server) use (&$clients, &$udpPeers, &$buffers, &$frameQueue, &$streamKeys, &$lastSeen, &$channelDecode, $BUFFER_TARGET, $FRAME_TARGET, $MAX_SILENCE) {
    $controlFile = 'audio_control.txt';
    file_put_contents($controlFile, '');
    // Cria arquivo vazio
    echo "📁 Arquivo de controle criado: {$controlFile}\n";
    echo "💡 Para parar o servidor, escreva 'STOP' no arquivo {$controlFile}\n";
    go(function () use ($controlFile) {
        while (true) {
            \Swoole\Coroutine::sleep(2);
            // Verifica a cada 2 segundos
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
    go(function () use ($server, &$clients, &$buffers, &$frameQueue, &$lastSeen, &$channelDecode, &$udpPeers, $BUFFER_TARGET, $FRAME_TARGET, $MAX_SILENCE) {
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


            [$rtpRaw, $callId, $ssrc, $portHandle, $codec, $frequency] = $realData;

            if (empty($frequency) || empty($codec)) {
                echo "⚠️ Codec ou frequência inválidos: {$data}\n";
                continue;
            }


            $FRAME_TARGET = strlen($rtpRaw);
            // $rtpRaw = resampler($rtpRaw, $frequency, cache::get('rateCall'));


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
            $buffers[$callId][$ssrc] ??= '';
            $buffers[$callId][$ssrc] .= $decoded;
            if (strlen($buffers[$callId][$ssrc]) > ($frequency * 4)) {
                $buffers[$callId][$ssrc] = '';
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

                $mixed = mixAudioChannels($validChunks, $frequency);

            }
            if ($mixed) {
                $frameQueue[$callId][] = $mixed;
                if (count($frameQueue[$callId]) >= $BUFFER_TARGET) {
                    $sendData = implode('', $frameQueue[$callId]);


                    $frameQueue[$callId] = [];
                    foreach ($clients[$callId] as $fd) {


                        $server->push($fd, $sendData, SWOOLE_WEBSOCKET_OPCODE_BINARY);
                    }


                }
            }
            if (time() - $lastGC > 15) {
                $current = time();
                foreach ($lastSeen as $cId => $ssrcs) {
                    if (empty($clients[$cId])) {
                        unset($lastSeen[$cId], $buffers[$cId], $frameQueue[$cId]);
                        foreach ($ssrcs as $s => $_) {
                            unset($channelDecode[$s]);
                        }
                        echo "🗑️ Removido callId inativo: {$cId}\n";
                        continue;
                    }
                    foreach ($ssrcs as $s => $ts) {
                        if ($current - $ts > $MAX_SILENCE) {
                            unset($channelDecode[$s], $lastSeen[$cId][$s], $buffers[$cId][$s]);
                            echo "🗑️ Removido canal inativo: {$cId}/{$s}\n";
                        }
                    }
                    if (empty($lastSeen[$cId])) {
                        unset($lastSeen[$cId], $buffers[$cId], $frameQueue[$cId]);
                    }
                }
                $lastGC = time();
                gc_collect_cycles();
            }
        }
    });
});
/**
 * 🔥 HTTP: endpoint para consulta de DTMF keys e página de exemplo
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
 * 🔥 WebSocket: cliente conecta para receber áudio ao vivo
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
        //\libspech\Cli\cli::pcl("{$callId} - {$ssrc} - {$frame->opcode} - {$frame->fd} ".strlen($pcmData)." bytes -> {$peerInfo['host']}:{$peerInfo['port']}");
        $packet = $pcmData . '__::__' . $callId . '__::__' . $ssrc;


        if (!empty($udpPeers[$callId])) {
            go(function () use ($packet, $callId, $ssrc, &$udpPeers) {
                $udp = new Swoole\Coroutine\Socket(AF_INET, SOCK_DGRAM, 0);
                foreach ($udpPeers[$callId] as $peerSsrc => $peerInfo) {
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
 * 🔥 Mixagem com normalização leve
 */
function normalizeAndMix(array $chunks): string
{
    if (count($chunks) === 1) {
        return reset($chunks);
    }
    $minLen = min(array_map("strlen", $chunks));
    $minLen -= $minLen % 2;
    $result = '';
    for ($i = 0; $i < $minLen; $i += 2) {
        $sum = 0;
        foreach ($chunks as $buf) {
            $sample = unpack("s", substr($buf, $i, 2))[1];
            $sum += $sample;
        }
        $avg = (int)($sum / count($chunks));
        $avg = max(-32768, min(32767, $avg * 1.2));
        // Normalização leve
        $result .= pack("s", $avg);
    }
    return $result;
}

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