import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import fs from 'node:fs';

const require = createRequire(import.meta.url);
const uplink = require('../js/mic-uplink.js');

const pcm = new Int16Array(160);
pcm[0] = -32768;
pcm[159] = 32767;
const packet = uplink.encodeFrame(0xfedcba98, 1234, 8000, pcm, 0);
const view = new DataView(packet);
assert.equal(view.getUint16(0, false), 0x4d55);
assert.equal(view.getUint8(2), 1);
assert.equal(view.getUint32(4, false), 0xfedcba98);
assert.equal(view.getUint32(8, false), 1234);
assert.equal(view.getUint16(12, false), 8000);
assert.equal(view.getUint16(14, false), 160);
assert.equal(view.getUint16(16, false), 320);
assert.equal(packet.byteLength, 340);

const metrics = new uplink.MicQualityMetrics();
const queue = new uplink.MicUplinkQueue({sampleRate: 8000}, metrics);
for (let i = 0; i < 10; i++) queue.enqueue({sequence: i, packet});
assert.equal(queue.frames.length, 8);
assert.deepEqual(queue.frames.map(frame => frame.sequence), [2, 3, 4, 5, 6, 7, 8, 9]);
assert.equal(metrics.uplinkDroppedOldFrames, 2);
assert.equal(metrics.browserQueuePeakMs, 160);

const sent = [];
const socket = {readyState: 1, bufferedAmount: 32 * 1024, send(data) { sent.push(data); }};
assert.equal(queue.sendOne(socket, 0), false, 'backpressure threshold ignored');
assert.equal(queue.frames.length, 3, 'congested queue did not retain only 60ms of recent audio');
socket.bufferedAmount = 0;
assert.equal(queue.sendOne(socket, 0), true);
assert.equal(queue.sendOne(socket, 0), false, 'same-tick burst emitted');
assert.equal(queue.sendOne(socket, 19), false);
assert.equal(queue.sendOne(socket, 20), true);
assert.equal(sent.length, 2);

metrics.observeSamples(pcm);
assert.equal(metrics.clippedSamples, 2);
assert.ok(metrics.snapshot().clippingPercent > 1);
metrics.reset();
assert.equal(metrics.capturedFrames, 0);
assert.equal(metrics.wsBufferedPeak, 0);

assert.equal(uplink.qualityState({uplinkJitterP95: 8, browserQueueMs: 40}), 'excellent');
assert.equal(uplink.qualityState({capturedFrames: 0, uplinkJitterP95: 0}), 'good');
assert.equal(uplink.qualityState({uplinkJitterP95: 20, browserQueueMs: 70}), 'good');
assert.equal(uplink.qualityState({uplinkJitterP95: 40}), 'unstable');
assert.equal(uplink.qualityState({uplinkJitterP95: 70}), 'poor');
assert.equal(uplink.qualityState({wsBufferedAmount: 300000}), 'critical');

const html = fs.readFileSync(new URL('../plugins/Request/pages/default.html', import.meta.url), 'utf8');
const router = fs.readFileSync(new URL('../js/router.js', import.meta.url), 'utf8');
for (const id of ['micQualityPanel', 'micQualityState', 'micQualityJitter', 'micQualityQueue', 'micQualityDrops', 'micQualityWs']) {
    assert.ok(html.includes(`id="${id}"`), `quality UI missing ${id}`);
}
for (const label of ['Excelente', 'Boa', 'Instável', 'Ruim', 'Crítica']) {
    assert.ok(html.includes(label), `quality label missing ${label}`);
}
assert.ok(html.includes('resetMicUplinkState(false)'), 'hangup/reset cleanup missing');
assert.ok(html.includes("autoGainControl: micAgcEnabled.checked"), 'AGC A/B setting not connected');
assert.ok(router.includes("window.handleCallActive") && router.includes("window.startAudioCapture"), 'inbound does not use shared microphone pipeline');
assert.ok(html.includes("window.startAudioCapture = async") && html.includes("btnCall"), 'outbound does not use shared microphone pipeline');

console.log('OK: binary frame, bounded queue, backpressure, pacing, clipping, quality UI states and reset.');
