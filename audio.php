<?php

use libspech\Cache\cache;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

\Swoole\Runtime::enableCoroutine();

ini_set('memory_limit', '4024M');
include 'libspech/plugins/autoloader.php';

$clients = []; // callId => [fd => fd] (WebSocket connections)
$clientInfo = []; // fd => [callId, ssrc] (Info das conexões)
$buffers = []; // callId => [ssrc => buffer PCM]
$lastSeen = []; // callId => [ssrc => timestamp]
$frameQueue = []; // callId => [frames PCM]
$streamKeys = []; // callId => [endpointId => DTMF string]
$udpPeers = []; // callId => [ssrc => [host, port]] (Endereços UDP dos peers)

// Configuração
$BUFFER_TARGET = 2;      // frames por envio (~40ms por pacote)
$FRAME_TARGET = 320; // 320 bytes (~20ms PCM16 mono por frame)
//var_dump($FRAME_TARGET);exit;
$MAX_SILENCE = 30;     // segundos até GC de SSRC inativo
//while(true)sleep(1);
/**
 * Configuração do servidor WebSocket
 */
$server = new Server("0.0.0.0", 8888, SWOOLE_BASE, SWOOLE_SOCK_TCP | SWOOLE_SSL);
$server->set([
    'ssl_cert_file' => 'fullchain.pem',
    'ssl_key_file' => 'privkey.pem',
]);

/**
 * 🔥 Listener UDP: recebe pacotes PCM decodificados
 */
$channelDecode = []; // Mover para escopo global
cache::define('rateCall', 8000);
$server->on("start", function (Server $server) use (&$clients, &$udpPeers, &$buffers, &$frameQueue, &$streamKeys, &$lastSeen, &$channelDecode, $BUFFER_TARGET, $FRAME_TARGET, $MAX_SILENCE) {
    // 🔥 Sistema de controle via arquivo
    $controlFile = 'audio_control.txt';
    file_put_contents($controlFile, ''); // Cria arquivo vazio
    echo "📁 Arquivo de controle criado: {$controlFile}\n";
    echo "💡 Para parar o servidor, escreva 'STOP' no arquivo {$controlFile}\n";

    // Monitor do arquivo de controle
    go(function () use ($controlFile) {
        while (true) {
            \Swoole\Coroutine::sleep(2); // Verifica a cada 2 segundos

            if (file_exists($controlFile)) {
                $content = trim(file_get_contents($controlFile));
                if (!empty($content) && (strtoupper($content) === 'STOP' || strtoupper($content) === 'EXIT')) {
                    echo "🛑 Comando de parada recebido via arquivo de controle!\n";
                    echo "🔄 Encerrando servidor graciosamente...\n";

                    // Limpa o arquivo
                    file_put_contents($controlFile, '');

                    // Para o servidor
                    throw new \Exception("Shutdown solicitado via arquivo de controle");
                }
            }
        }
    });

    // UDP áudio PCM
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


            // 🔹 Desmonta pacote UDP personalizado: [RTP_RAW]__::__[CALLID]__::__[SSRC]__::__[PORT]
            $realData = explode('__::__', $data, 4);
            if (count($realData) < 3) {
                continue;
            }

            [$rtpRaw, $callId, $ssrc, $portHandle] = $realData;

            // 🔸 Armazena endereço UDP do peer para resposta


            if (empty($peer['address']) && empty($peer['port'])) {
                $udp->sendto('127.0.0.1', $portHandle, SOCKET_EREMOTE);
                $res = $udp->recvfrom($peer, 1);



            }


            if (!empty($peer['address']) && !empty($peer['port'])) {
                $udpPeers[$callId] ??= [];
                $udpPeers[$callId]["{$peer['address']}{$peer['port']}"] = [
                    'host' => $peer['address'],
                    'port' => $peer['port']
                ];
            }

            if (empty($clients[$callId])) {
                unset($buffers[$callId], $frameQueue[$callId]);
                continue;
            }

            // 🔸 Atualiza tempo de última atividade para o SSRC
            $lastSeen[$callId][$ssrc] = time();


            // 🔸 Decodificação por codec
            $decoded = $rtpRaw;


            // 🔸 Bufferiza por SSRC
            $buffers[$callId][$ssrc] ??= '';
            $buffers[$callId][$ssrc] .= $decoded;

            // 🔸 Limita tamanho do buffer por canal
            if (strlen($buffers[$callId][$ssrc]) > 1920 * 4) {
                $buffers[$callId][$ssrc] = '';
            }

            // 🔹 🔥 Lógica adaptativa para mixagem
            $validChunks = [];
            foreach ($buffers[$callId] as $source => $buf) {
                if (strlen($buf) >= $FRAME_TARGET) {
                    $validChunks[$source] = substr($buf, 0, $FRAME_TARGET);
                }
            }

            // 🔍 Verifica quantas fontes estão ativas nos últimos 2 segundos
            $activeSources = [];
            $now = time();
            foreach ($lastSeen[$callId] ?? [] as $src => $ts) {
                if ($now - $ts < 2) {
                    $activeSources[] = $src;
                }
            }

            $mixed = null;
            if (count($activeSources) === 1 && count($validChunks) >= 1) {
                // 💡 Só uma fonte ativa: envia direto
                $only = array_key_first($validChunks);
                $mixed = $validChunks[$only];
                $buffers[$callId][$only] = substr($buffers[$callId][$only], $FRAME_TARGET);
            } elseif (count($activeSources) >= 2 && count($validChunks) >= 2) {
                // 🎛️ Múltiplas fontes ativas: faz mixagem normalizada
                foreach ($validChunks as $src => $chunk) {
                    $buffers[$callId][$src] = substr($buffers[$callId][$src], $FRAME_TARGET);
                }
                $mixed = mixAudioChannels($validChunks, 8000);
            }

            if ($mixed) {
                $frameQueue[$callId][] = $mixed;

                // 🧃 Envia áudio se buffer atingir limite
                if (count($frameQueue[$callId]) >= $BUFFER_TARGET) {
                    $sendData = implode('', $frameQueue[$callId]);
                    $frameQueue[$callId] = [];

                    foreach ($clients[$callId] as $fd) {
                        $server->push($fd, $sendData, SWOOLE_WEBSOCKET_OPCODE_BINARY);
                    }
                }
            }

            // ♻️ Coleta de lixo para canais inativos
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

    // SSRC opcional para envio de áudio (usado quando cliente quiser enviar PCM)
    $ssrc = $req->get["ssrc"] ?? "ws-{$req->fd}";

    if (!isset($clients[$fp])) $clients[$fp] = [];
    $clients[$fp][$req->fd] = $req->fd;

    // Armazena info da conexão para uso no message handler
    $clientInfo[$req->fd] = [
        'callId' => $fp,
        'ssrc' => $ssrc
    ];


    $pcm_data = '';
    $sample_rate = 8000;
    $duration = 0.05;
    $frequency = 440;
    $samples = (int)($sample_rate * $duration);
    for ($i = 0; $i < $samples; $i++) {
        $sample = sin(2 * M_PI * $frequency * $i / $sample_rate) * 32767 * 0.5;
        $pcm_data .= pack('s', (int)$sample);
    }
    $server->push($req->fd, str_split($pcm_data, 320)[0], SWOOLE_WEBSOCKET_OPCODE_BINARY);

    echo "👂 Cliente WebSocket conectado - CallID={$fp}, SSRC={$ssrc}, FD={$req->fd}\n";
});

/**
 * WebSocket: recebe PCM do cliente e encaminha para UDP peers
 * Formato: PCM binário puro (Int16Array)
 */
$server->on("message", function (Server $server, Frame $frame) use (&$clientInfo, &$udpPeers) {
    // Verifica se a conexão tem info registrada
    if (!isset($clientInfo[$frame->fd])) {
        echo "⚠️ Conexão sem info registrada - FD: {$frame->fd}\n";
        return;
    }

    $callId = $clientInfo[$frame->fd]['callId'];
    $ssrc = $clientInfo[$frame->fd]['ssrc'];

    // Se for binário, trata como PCM puro
    if ($frame->opcode === SWOOLE_WEBSOCKET_OPCODE_BINARY) {
        $pcmData = $frame->data;

        if (strlen($pcmData) === 0) {
            return;
        }

        // Monta pacote no formato: [RTP_RAW]__::__[CALLID]__::__[SSRC]
        $packet = $pcmData . '__::__' . $callId . '__::__' . $ssrc;

        // 🔥 Envia para todos os peers UDP registrados desta chamada (exceto o próprio ssrc)
        if (!empty($udpPeers[$callId])) {
            go(function () use ($packet, $callId, $ssrc, &$udpPeers) {
                $udp = new Swoole\Coroutine\Socket(AF_INET, SOCK_DGRAM, 0);

                foreach ($udpPeers[$callId] as $peerSsrc => $peerInfo) {
                    // Não envia de volta para o mesmo SSRC
                    if ($peerSsrc === $ssrc) continue;

                    $udp->sendto($peerInfo['host'], $peerInfo['port'], $packet);
                   // echo "📤 PCM enviado para {$peerInfo['host']}:{$peerInfo['port']} - CallID: {$callId}, SSRC: {$ssrc} -> {$peerSsrc}, Size: " . strlen($packet) . " bytes\n";
                }

                $udp->close();
            });
        } else {


            echo "⚠️ Nenhum peer UDP registrado para CallID: {$callId}\n";
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
            if (empty($list)) unset($clients[$callId]);
            unset($buffers[$callId]);
            break;
        }
    }

    // Remove info do cliente
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
    if (count($chunks) === 1) return reset($chunks);

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
        $avg = max(-32768, min(32767, $avg * 1.2)); // Normalização leve
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
    return pack('a4V', 'RIFF', 36 + $dataLength)
        . 'WAVE'
        . pack('a4VvvVVvv', 'fmt ', 16, 1, $channels,
            $sampleRate, (int)$byteRate, (int)$blockAlign, $bitsPerSample)
        . pack('a4V', 'data', $dataLength);
}