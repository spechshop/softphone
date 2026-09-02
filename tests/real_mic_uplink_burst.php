<?php

/**
 * Local integration: real WSS burst -> audio.php jitter/pacer -> UDP bridge input
 * -> existing PCMA encoder/rtpChannel. It starts and terminates its own audio.php.
 */

require_once __DIR__ . '/../plugins/Utils/helpers/MicUplinkFrame.php';
require_once __DIR__ . '/../plugins/Utils/helpers/AudioIpcPacket.php';
require_once __DIR__ . '/../libspech/plugins/autoloader.php';

use helpers\utils\AudioIpcPacket;
use helpers\utils\MicUplinkFrame;
use libspech\Rtp\rtpChannel;
use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\Socket;

function integrationPercentile(array $values, float $p): float
{
    sort($values, SORT_NUMERIC);
    return $values[(int)max(0, min(count($values) - 1, ceil(count($values) * $p) - 1))];
}

$cwd = dirname(__DIR__);
$pipes = [];
$process = proc_open(
    [PHP_BINARY, 'audio.php'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $cwd
);
if (!is_resource($process)) throw new RuntimeException('could not start audio.php');
foreach ([1, 2] as $pipe) stream_set_blocking($pipes[$pipe], false);

try {
    $ready = false;
    $startupLog = '';
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $startupLog .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        if (str_contains($startupLog, 'Servidor UDP aguardando pacotes')) {
            $ready = true;
            break;
        }
        usleep(20_000);
    }
    if (!$ready) {
        $startupLog .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        throw new RuntimeException("audio.php did not listen: $startupLog");
    }

    $result = null;
    Swoole\Coroutine\run(function () use (&$result): void {
        $stream = 'mic-burst-' . bin2hex(random_bytes(4));
        $udp = new Socket(AF_INET, SOCK_DGRAM, 0);
        if (!$udp->bind('127.0.0.1', 0)) throw new RuntimeException('UDP bind failed');
        $port = (int)$udp->getsockname()['port'];

        // Register the exact local UDP peer through the production binary IPC.
        $registration = new AudioIpcPacket(
            str_repeat("\x00", 320),
            $stream,
            'rtp-test',
            8000,
            1,
            $port,
        );
        $udp->sendto('127.0.0.1', 9966, $registration->encode());
        Swoole\Coroutine::sleep(0.03);

        $ws = new Client('127.0.0.1', 8889, true);
        $ws->set([
            'timeout' => 2,
            'ssl_verify_peer' => false,
            'ssl_allow_self_signed' => true,
        ]);
        if (!$ws->upgrade("/?fp={$stream}&ssrc=mic-integration&sampleRate=8000")) {
            throw new RuntimeException("WSS upgrade failed status={$ws->statusCode} err={$ws->errCode}");
        }

        $payload = str_repeat("\x00\x00", 160);
        for ($seq = 0; $seq < 10; $seq++) {
            $frame = new MicUplinkFrame($seq, $seq * 20, 8000, 160, $payload);
            if (!$ws->push($frame->encode(), SWOOLE_WEBSOCKET_OPCODE_BINARY)) {
                throw new RuntimeException("WSS push failed at seq=$seq");
            }
        }

        $channel = new rtpChannel(rtpChannel::PAYLOAD_PCMA, 8000, 20, 0x12345678);
        $times = [];
        $rtpSequences = [];
        $rtpTimestamps = [];
        for ($received = 0; $received < 10; $received++) {
            $peer = null;
            $raw = $udp->recvfrom($peer, 1.0);
            if (!is_string($raw) || $raw === '') throw new RuntimeException("UDP paced frame timeout at $received");
            $ipc = AudioIpcPacket::decode($raw);
            if (!$ipc instanceof AudioIpcPacket) throw new RuntimeException('paced frame is not binary IPC');
            $pcm = $ipc->payload;
            if (strlen($pcm) !== 320) throw new RuntimeException('unexpected paced PCM length');
            $rtp = $channel->buildAudioPacket(encodePcmToPcma($pcm));
            $header = unpack('nflags/nsequence/Ntimestamp/Nssrc', substr($rtp, 0, 12));
            $times[] = hrtime(true) / 1_000_000;
            $rtpSequences[] = $header['sequence'];
            $rtpTimestamps[] = $header['timestamp'];
        }
        $metricFrame = $ws->recv(1.0);
        $metricMessage = is_object($metricFrame) ? json_decode($metricFrame->data ?? '', true) : null;
        if (!is_array($metricMessage)
            || ($metricMessage['type'] ?? '') !== 'micQuality'
            || !isset($metricMessage['data']['rtpPacingGapP95'])) {
            throw new RuntimeException('server micQuality aggregate was not delivered');
        }
        $ws->close();
        $udp->close();

        $gaps = [];
        for ($i = 1; $i < count($times); $i++) $gaps[] = $times[$i] - $times[$i - 1];
        for ($i = 1; $i < count($rtpSequences); $i++) {
            if ((($rtpSequences[$i - 1] + 1) & 0xffff) !== $rtpSequences[$i]) {
                throw new RuntimeException('RTP sequence is not continuous');
            }
            if (($rtpTimestamps[$i] - $rtpTimestamps[$i - 1]) !== 160) {
                throw new RuntimeException('RTP timestamp did not advance by 160');
            }
        }
        $avg = array_sum($gaps) / count($gaps);
        $p95 = integrationPercentile($gaps, 0.95);
        $p99 = integrationPercentile($gaps, 0.99);
        $max = max($gaps);
        $min = min($gaps);
        if ($min < 15 || $max > 35) {
            throw new RuntimeException('real pacer gap outside 15..35ms: ' . json_encode($gaps));
        }
        $result = compact('gaps', 'avg', 'p95', 'p99', 'max', 'min', 'rtpSequences', 'rtpTimestamps');
        $result['quality'] = $metricMessage['data']['quality'];
    });

    echo sprintf(
        "OK REAL WSS_BURST->RTP packets=10 gap_avg=%.2fms p95=%.2fms p99=%.2fms min=%.2fms max=%.2fms rtp_ts_step=160 metrics=%s\n",
        $result['avg'], $result['p95'], $result['p99'], $result['min'], $result['max'], $result['quality']
    );
} finally {
    proc_terminate($process);
    usleep(100_000);
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    proc_close($process);
}
