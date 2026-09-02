<?php

require_once __DIR__ . '/../plugins/Utils/helpers/MicUplinkFrame.php';
require_once __DIR__ . '/../plugins/Utils/helpers/MicQualityMetrics.php';
require_once __DIR__ . '/../plugins/Utils/helpers/MicJitterBuffer.php';
require_once __DIR__ . '/../plugins/Utils/helpers/RtpPacer.php';
require_once __DIR__ . '/../plugins/Utils/helpers/MicUplinkSession.php';

use helpers\utils\MicUplinkFrame;
use helpers\utils\MicUplinkSession;

function runVirtualStress(int $sessions, int $durationSeconds): array
{
    $all = [];
    for ($i = 0; $i < $sessions; $i++) {
        $all[] = new MicUplinkSession($i + 1, "stress-$i", "mic-$i", 8000, targetMs: 0, maxFrameAgeMs: 1000);
    }
    $pcm = str_repeat("\x00\x00", 160);
    $started = hrtime(true);
    $ticks = intdiv($durationSeconds * 1000, 20);
    for ($seq = 0; $seq < $ticks; $seq++) {
        $now = $seq * 20;
        foreach ($all as $session) {
            $frame = new MicUplinkFrame($seq, $now, 8000, 160, $pcm);
            $session->ingest($frame, $now);
            $session->startIfReady($now);
            $session->tick($now);
        }
    }
    $elapsedMs = (hrtime(true) - $started) / 1_000_000;
    $packets = $sessions * $ticks;
    foreach ($all as $session) $session->close();
    unset($all);
    gc_collect_cycles();
    return [$elapsedMs, $packets, memory_get_usage(true), memory_get_peak_usage(true)];
}

foreach ([10, 30, 50, 100] as $count) {
    [$ms, $packets, $memory, $peak] = runVirtualStress($count, 60);
    echo sprintf(
        "VIRTUAL_STRESS sessions=%d duration=60s packets=%d cpu_wall=%.1fms memory=%.1fMB peak=%.1fMB\n",
        $count, $packets, $ms, $memory / 1048576, $peak / 1048576
    );
}

[$ms, $packets, $memory, $peak] = runVirtualStress(1, 1800);
echo sprintf(
    "VIRTUAL_LONG sessions=1 duration=30min packets=%d cpu_wall=%.1fms memory=%.1fMB peak=%.1fMB\n",
    $packets, $ms, $memory / 1048576, $peak / 1048576
);

$baseline = memory_get_usage(true);
for ($cycle = 0; $cycle < 100; $cycle++) runVirtualStress(1, 1);
$after = memory_get_usage(true);
if ($after > $baseline + 2 * 1048576) throw new RuntimeException('memory did not return near baseline');
echo sprintf("VIRTUAL_LEAK cycles=100 baseline=%.1fMB after=%.1fMB delta=%.1fMB\n",
    $baseline / 1048576, $after / 1048576, ($after - $baseline) / 1048576);
