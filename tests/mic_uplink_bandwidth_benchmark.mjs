import {createRequire} from 'node:module';
const require = createRequire(import.meta.url);
const {MicQualityMetrics, MicUplinkQueue, encodeFrame} = require('../js/mic-uplink.js');

const durationMs = 60_000;
const sampleRate = 8000;
const pcm = new Int16Array(160);
const packet = encodeFrame(0, 0, sampleRate, pcm, 0);

function baseline(kbps) {
    let buffered = 0;
    let peak = 0;
    const bytesPerMs = kbps * 1000 / 8 / 1000;
    for (let now = 0; now < durationMs; now++) {
        buffered = Math.max(0, buffered - bytesPerMs);
        if (now % 20 === 0) buffered += packet.byteLength;
        peak = Math.max(peak, buffered);
    }
    return {peak, delayMs: buffered / bytesPerMs};
}

function bounded(kbps) {
    const metrics = new MicQualityMetrics();
    const queue = new MicUplinkQueue({sampleRate}, metrics);
    const bytesPerMs = kbps * 1000 / 8 / 1000;
    const socket = {
        readyState: 1,
        bufferedAmount: 0,
        send(data) { this.bufferedAmount += data.byteLength; }
    };
    for (let now = 0, seq = 0; now < durationMs; now++) {
        socket.bufferedAmount = Math.max(0, socket.bufferedAmount - bytesPerMs);
        if (now % 20 === 0) {
            queue.enqueue({sequence: seq, packet});
            seq++;
        }
        queue.drain(socket);
    }
    const snapshot = metrics.snapshot();
    return {
        wsPeak: snapshot.wsBufferedPeak,
        queuePeakMs: snapshot.browserQueuePeakMs,
        drops: snapshot.droppedFrames,
        dropPercent: snapshot.dropPercent,
        delayMs: socket.bufferedAmount / bytesPerMs,
    };
}

console.log('SIMULATION only: PCM16/8k, 20ms, 20-byte header, 60s; this is not tc netem.');
console.log('kbps,baselineWsPeakKB,baselineBacklogMs,boundedWsPeakKB,boundedBacklogMs,queuePeakMs,drops,dropPercent');
for (const kbps of [1024, 512, 256, 128, 64, 32]) {
    const old = baseline(kbps);
    const next = bounded(kbps);
    console.log([
        kbps,
        (old.peak / 1024).toFixed(1),
        old.delayMs.toFixed(0),
        (next.wsPeak / 1024).toFixed(1),
        next.delayMs.toFixed(0),
        next.queuePeakMs,
        next.drops,
        next.dropPercent.toFixed(1),
    ].join(','));
}
