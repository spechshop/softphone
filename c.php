<?php

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Coroutine\Socket;
use plugins\Utils\cache;
use libspech\Rtp\rtpc;

ini_set('memory_limit', '1024M');
include 'libspech/plugins/autoloader.php';

// -------------------------------------------------------
// CONFIG
// -------------------------------------------------------
$UDP_PORT    = 9600;    // RTP bruto
$HTTP_PORT   = 8889;    // HTTP para o player
$PCM_RATE    = 8000;    // tudo converte pra 8k mono 16-bit
$FRAME_BYTES = 320;     // 20ms @ 8k = 160 samples * 2 bytes

$clients = [];  // callId => [fd => Response]
$buffers = [];  // callId => [ssrc => PCM string]

// -------------------------------------------------------
// WAV HEADER INFINITO
// -------------------------------------------------------
function wavHeaderInfinite(int $rate = 8000, int $channels = 1): string
{
    $bits = 16;
    $byteRate   = $rate * $channels * ($bits / 8);
    $blockAlign = $channels * ($bits / 8);

    return "RIFF"
        . pack("V", 0xFFFFFFFF)
        . "WAVEfmt "
        . pack("V", 16)
        . pack("v", 1)
        . pack("v", $channels)
        . pack("V", $rate)
        . pack("V", $byteRate)
        . pack("v", $blockAlign)
        . pack("v", $bits)
        . "data"
        . pack("V", 0xFFFFFFFF);
}

// -------------------------------------------------------
// MIXER (igual teu normalizeAndMix, mantido)
// -------------------------------------------------------
function normalizeAndMix(array $chunks): string
{
    if (count($chunks) === 1) {
        return reset($chunks);
    }

    $minLen = min(array_map('strlen', $chunks));
    $minLen -= $minLen % 2;
    if ($minLen <= 0) return '';

    $result = '';

    for ($i = 0; $i < $minLen; $i += 2) {
        $sum = 0;
        foreach ($chunks as $buf) {
            $sum += unpack('s', substr($buf, $i, 2))[1];
        }
        $avg = (int)($sum / count($chunks));
        $avg = max(-32768, min(32767, (int)($avg * 1.2)));
        $result .= pack('s', $avg);
    }

    return $result;
}

// -------------------------------------------------------
// DECODER: pacote UDP -> [callId, ssrc, pcm8k]
// Formato do UDP: RTP_RAW__::__CALLID__::__SSRC:codec:freq
// -------------------------------------------------------
function decodeUdpPacketToPcm(string $packet, int $targetRate = 8000): array
{
    static $channels = [];

    $parts = explode('__::__', $packet, 3);
    if (count($parts) < 3) {
        return [null, null, ''];
    }

    [$rtpRaw, $callId, $ssrcInfo] = $parts;

    $ss = explode(':', $ssrcInfo);
    if (count($ss) < 3) {
        return [$callId, null, ''];
    }

    $freq      = (int) array_pop($ss);
    $codecName = strtoupper(array_pop($ss));
    $ssrc      = implode(':', $ss);

    if ($freq <= 0) $freq = 8000;

    $rtp     = new rtpc($rtpRaw);
    $payload = $rtp->payloadRaw;

    // cria / pega decoder por SSRC
    if (!isset($channels[$ssrc])) {
        switch ($codecName) {
            case 'G729':
                $channels[$ssrc] = new bcg729Channel();
                break;

            case 'OPUS':
                $ch = new opusChannel($freq, 1);
                $ch->setBitrate(24000);
                $ch->setSignalVoice(true);
                $channels[$ssrc] = $ch;
                break;

            default:
                $channels[$ssrc] = null;
        }
    }

    $pcm = match ($codecName) {
        'G729' => $channels[$ssrc]
            ? ($channels[$ssrc]->decode($payload) ?: str_repeat("\x00\x00", 160))
            : str_repeat("\x00\x00", 160),

        'PCMU' => decodePcmuToPcm($payload),
        'PCMA' => decodePcmaToPcm($payload),

        'OPUS' => ($channels[$ssrc]
            ? $channels[$ssrc]->decode($payload, 24000)
            : '') ?: '',

        'L16'  => pcmLeToBe($payload),

        default => $payload,
    };

    // normaliza pra 8k se necessário
    if ($codecName === 'OPUS' || $codecName === 'L16' || $freq !== $targetRate) {
        if ($pcm !== '') {
            $pcm = resampler($pcm, $freq, $targetRate, false);
        }
    }

    return [$callId, $ssrc, $pcm];
}

// -------------------------------------------------------
// SERVER HTTP
// -------------------------------------------------------
$server = new Server('0.0.0.0', $HTTP_PORT);
$server->set([
    'worker_num' => 1,
]);

// -------------------------------------------------------
// onStart: listener UDP
// -------------------------------------------------------
$server->on('start', function () use (&$buffers, $UDP_PORT, $PCM_RATE) {
    echo "🚀 AUDIO VIEWER NOVO iniciado\n";
    echo "➡ HTTP: http://127.0.0.1:8889/stream?callId=XYZ\n";
    echo "➡ UDP : 0.0.0.0:{$UDP_PORT} (RTP_RAW__::__CALLID__::__SSRC:codec:freq)\n";

    // tamanho do frame PCM (20ms @ 8kHz)
$FRAME_BYTES = 320;

// quantos frames acumular antes de liberar (200ms a 300ms é ótimo)
$PREBUFFER_FRAMES = 12;   // 12 × 20ms = 240ms

// buffers de saída por cliente
$outBuffer = [];  // callId => pcm acumulado

go(function () use (&$buffers, &$clients, &$outBuffer, $callId, $res, $FRAME_BYTES, $PREBUFFER_FRAMES) {

    while (true) {

        if (!$res->isWritable()) {
            unset($clients[$callId][$res->fd]);
            echo "❌ Cliente saiu ({$callId})\n";
            break;
        }

        // nada novo vindo dos SSRC? aguarda
        if (empty($buffers[$callId])) {
            Swoole\Coroutine::sleep(0.01);
            continue;
        }

        // junta frames de cada SSRC
        $frames = [];

        foreach ($buffers[$callId] as $ssrc => &$buf) {
            if (strlen($buf) >= $FRAME_BYTES) {
                $frames[] = substr($buf, 0, $FRAME_BYTES);
                $buf      = substr($buf, $FRAME_BYTES);
            }
        }
        unset($buf);

        // nada pronto pra mixar
        if (empty($frames)) {
            Swoole\Coroutine::sleep(0.005);
            continue;
        }

        // mix final
        $mixed = normalizeAndMix($frames);

        // acumula o mix no pré-buffer
        if (!isset($outBuffer[$callId])) {
            $outBuffer[$callId] = '';
        }
        $outBuffer[$callId] .= $mixed;

        // só envia quando tiver pré-buffer suficiente
        $minBytes = $PREBUFFER_FRAMES * $FRAME_BYTES;
        if (strlen($outBuffer[$callId]) < $minBytes) {
            continue;
        }

        // envia 1 frame (20ms)
        $chunk = substr($outBuffer[$callId], 0, $FRAME_BYTES);
        $outBuffer[$callId] = substr($outBuffer[$callId], $FRAME_BYTES);

        $res->write($chunk);
    }
});

});

// -------------------------------------------------------
// HTTP /stream?callId=XYZ
// -------------------------------------------------------
$server->on('request', function (Request $req, Response $res)
    use (&$clients, &$buffers, $PCM_RATE, $FRAME_BYTES) {
$res->header("Accept-Ranges", "bytes");

    // CORS / preflight
    if ($req->server['request_method'] === 'OPTIONS') {
        $res->header('Access-Control-Allow-Origin', '*');
        $res->header('Access-Control-Allow-Methods', 'GET, OPTIONS');
        $res->header('Access-Control-Allow-Headers', '*');
        return $res->end();
    }

    if ($req->server['request_uri'] !== '/stream') {
        $res->status(404);
        $res->header('Access-Control-Allow-Origin', '*');
        return $res->end('Not Found');
    }

    $callId = $req->get['callId'] ?? null;
    if (!$callId) {
        $res->status(400);
        $res->header('Access-Control-Allow-Origin', '*');
        return $res->end('callId required');
    }

    $res->header('Access-Control-Allow-Origin', '*');
    $res->header('Access-Control-Allow-Headers', '*');
    $res->header('Access-Control-Expose-Headers', '*');

    $res->header('Content-Type', 'audio/wav');
    $res->header('Transfer-Encoding', 'chunked');
    $res->header('Connection', 'keep-alive');
    $res->header('Cache-Control', 'no-cache');

    // WAV header (infinito)
    $res->write(wavHeaderInfinite($PCM_RATE, 1));

    echo "👂 Cliente conectado em callId={$callId}\n";

    if (!isset($clients[$callId])) {
        $clients[$callId] = [];
    }
    $clients[$callId][$res->fd] = $res;

    // coroutine de envio contínuo
    go(function () use (&$buffers, &$clients, $callId, $res, $FRAME_BYTES) {

        while (true) {
            if (!$res->isWritable()) {
                unset($clients[$callId][$res->fd]);
                echo "❌ Cliente saiu ({$callId})\n";
                break;
            }

            if (empty($buffers[$callId])) {
                Swoole\Coroutine::sleep(0.01);
                continue;
            }

            $frames = [];

            foreach ($buffers[$callId] as $ssrc => &$buf) {
                if (strlen($buf) >= $FRAME_BYTES) {
                    $frames[] = substr($buf, 0, $FRAME_BYTES);
                    $buf      = substr($buf, $FRAME_BYTES);
                }
            }
            unset($buf);

            if (empty($frames)) {
                Swoole\Coroutine::sleep(0.01);
                continue;
            }

            $mixed = normalizeAndMix($frames);
            if ($mixed !== '') {
                $res->write($mixed);
            } else {
                Swoole\Coroutine::sleep(0.005);
            }
        }
    });
});

// -------------------------------------------------------
// START
// -------------------------------------------------------
$server->start();
