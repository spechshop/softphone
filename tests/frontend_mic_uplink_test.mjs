import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import fs from 'node:fs';
import vm from 'node:vm';

const require = createRequire(import.meta.url);
const uplink = require('../js/mic-uplink.js');
const opus = require('../js/opus-config.js');

assert.deepEqual(opus.normalize(null), opus.DEFAULTS);
assert.equal(opus.resolveCapture(opus.PRESETS.stereo, {channelCount: 2}).actualChannels, 2);
assert.equal(opus.normalize({channels: 2, stereo: false}).channels, 1);
const monoFallback = opus.resolveCapture(opus.PRESETS.stereo, {channelCount: 1});
assert.equal(monoFallback.fallback, true);
assert.equal(monoFallback.config.channels, 1);
assert.equal(monoFallback.config.stereo, false);
for (const bitrate of opus.BITRATES) assert.equal(opus.normalize({maxAverageBitrate: bitrate}).maxAverageBitrate, bitrate);
for (const ptime of opus.PTIMES) assert.equal(opus.normalize({ptime}).ptime, ptime);
assert.equal(opus.normalize({ptime: 30}).ptime, 20);

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
const stereoPcm = new Int16Array(1920);
stereoPcm[0] = 1000;
stereoPcm[1] = -1000;
const stereoPacket = uplink.encodeFrame(1, 20, 48000, stereoPcm, uplink.FLAG_STEREO);
const stereoView = new DataView(stereoPacket);
assert.equal(uplink.channelsFromFlags(stereoView.getUint16(18, false)), 2);
assert.equal(stereoView.getUint16(14, false), 1920);
assert.equal(stereoPacket.byteLength, 3860);
const tenMsPcm = new Int16Array(480);
const tenMsPacket = uplink.encodeFrame(2, 20, 48000, tenMsPcm, uplink.FLAG_FRAME_10MS);
assert.equal(new DataView(tenMsPacket).getUint16(14, false), 480);
assert.equal(tenMsPacket.byteLength, 980);

const metrics = new uplink.MicQualityMetrics();
const queue = new uplink.MicUplinkQueue({sampleRate: 8000}, metrics);
for (let i = 0; i < 10; i++) queue.enqueue({sequence: i, packet});
assert.equal(queue.frames.length, 8);
assert.deepEqual(queue.frames.map(frame => frame.sequence), [2, 3, 4, 5, 6, 7, 8, 9]);
assert.equal(metrics.uplinkDroppedOldFrames, 2);
assert.equal(metrics.browserQueuePeakMs, 160);

const sent = [];
const socket = {readyState: 1, bufferedAmount: 32 * 1024, send(data) { sent.push(data); }};
assert.equal(queue.sendOne(socket), false, 'backpressure threshold ignored');
assert.equal(queue.frames.length, 8, 'backpressure discarded frames before the hard limit');
socket.bufferedAmount = 0;
assert.equal(queue.drain(socket), 4, 'catch-up burst must be capped at four frames');
assert.equal(queue.frames.length, 4);
assert.equal(queue.drain(socket), 2, 'catch-up must return the queue to the 20-40ms target');
assert.equal(queue.frames.length * queue.config.frameMs, 40);
assert.equal(sent.length, 6);

// A 60ms scheduler pause adds three frames to an ordinary 40ms queue. One
// catch-up cycle must recover instead of preserving the extra delay forever.
const pauseMetrics = new uplink.MicQualityMetrics();
const pauseQueue = new uplink.MicUplinkQueue({sampleRate: 8000}, pauseMetrics);
for (let i = 0; i < 5; i++) pauseQueue.enqueue({sequence: i, packet});
const pauseSocket = {readyState: 1, bufferedAmount: 0, send() {}};
assert.equal(pauseQueue.frames.length * pauseQueue.config.frameMs, 100);
assert.equal(pauseQueue.drain(pauseSocket), 3, '60ms scheduler backlog was not caught up');
assert.equal(pauseQueue.frames.length * pauseQueue.config.frameMs, 40);
const pauseSnapshot = pauseMetrics.snapshot();
assert.equal(pauseSnapshot.wsBufferedAmount, 0);
assert.equal(pauseSnapshot.droppedFrames, 0);
assert.equal(pauseSnapshot.dropPercent, 0);

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
const head = fs.readFileSync(new URL('../plugins/Request/modules/includes/head.html', import.meta.url), 'utf8');
for (const id of ['micQualityPanel', 'micQualityState', 'micQualityJitter', 'micQualityQueue', 'micQualityDrops', 'micQualityWs', 'micQualityPacerUnderruns']) {
    assert.ok(html.includes(`id="${id}"`), `quality UI missing ${id}`);
}
for (const id of ['opusSettings', 'opusProfile', 'opusMono', 'opusStereo', 'opusBitrate', 'opusPlaybackRate', 'opusCaptureRate', 'opusFec', 'opusPtime']) {
    assert.ok(html.includes(`id="${id}"`), `Opus UI missing ${id}`);
}
for (const id of ['micInputDevice', 'btnRefreshMicrophones', 'btnSaveAudioConfig', 'audioConfigState']) {
    assert.ok(html.includes(`id="${id}"`), `microphone settings UI missing ${id}`);
}
const audioViewStart = html.indexOf('<section data-view="audio">');
const configViewStart = html.indexOf('<section data-view="config">');
for (const id of ['micInputDevice', 'opusMono', 'opusStereo', 'opusPtime']) {
    const position = html.indexOf(`id="${id}"`);
    assert.ok(position > audioViewStart && position < configViewStart, `${id} is not in the audio tab`);
}
assert.ok(html.includes("sendRecByToken({audio, opus}, 'saveAudioConfig')"), 'audio settings are not sent to backend');
assert.ok(html.includes('navigator.mediaDevices.enumerateDevices()'), 'microphone devices are not enumerated');
assert.ok(html.includes('deviceId: {exact: microphoneId}'), 'selected microphone is not used by getUserMedia');
assert.ok(html.includes('&ptime=${ptime}'), 'ptime is not sent to audio backend handshake');
assert.ok(html.includes('channelCount: desiredChannels'), 'capture does not request the desired channel count');
assert.ok(html.includes("addModule('/js/opus-recorder-worklet.js')"), 'stereo recorder worklet is not loaded');
assert.ok(html.includes('Estéreo indisponível neste dispositivo — usando mono'), 'hardware fallback warning missing');
assert.ok(html.includes('window.applyNegotiatedOpusPtime'), 'negotiated ptime does not reconfigure browser framing');
assert.ok(html.includes('const frameSamples = Math.floor(audioContext.sampleRate * (micFrameMs / 1000))'), 'browser framing is still fixed at capture startup');
assert.ok(head.includes('/js/opus-config.js'), 'canonical frontend Opus module is not loaded');

// Execute the real AudioWorklet processor with known independent L/R samples.
const workletSource = fs.readFileSync(new URL('../js/opus-recorder-worklet.js', import.meta.url), 'utf8');
let Processor;
let posted;
class AudioWorkletProcessorMock {
    constructor() { this.port = {postMessage(message) { posted = message; }}; }
}
vm.runInNewContext(workletSource, {
    AudioWorkletProcessor: AudioWorkletProcessorMock,
    registerProcessor(name, implementation) { assert.equal(name, 'recorder'); Processor = implementation; },
    Int16Array, Math
});
const processor = new Processor();
processor.process([[
    new Float32Array([0.25, 0.5, -0.25]),
    new Float32Array([-0.5, 0.75, 0.125])
]]);
assert.equal(posted.channels, 2);
assert.deepEqual(Array.from(new Int16Array(posted.buffer)), [8191, -16383, 16383, 24575, -8191, 4095]);
for (const label of ['Excelente', 'Boa', 'Instável', 'Ruim', 'Crítica']) {
    assert.ok(html.includes(label), `quality label missing ${label}`);
}
assert.ok(html.includes('resetMicUplinkState(false)'), 'hangup/reset cleanup missing');
assert.ok(html.includes("autoGainControl: micAgcEnabled.checked"), 'AGC A/B setting not connected');
assert.ok(router.includes("window.handleCallActive") && router.includes("window.startAudioCapture"), 'inbound does not use shared microphone pipeline');
assert.ok(html.includes("window.startAudioCapture = async") && html.includes("btnCall"), 'outbound does not use shared microphone pipeline');

console.log('OK: binary frame, bounded queue, backpressure, scheduler catch-up, clipping, quality UI states and reset.');
