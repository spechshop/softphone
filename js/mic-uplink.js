(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.SpechMicUplink = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const CONFIG = Object.freeze({
        frameMs: 20,
        targetQueueMs: 60,
        softQueueMs: 120,
        hardQueueMs: 160,
        wsHealthyBytes: 32 * 1024,
        wsWarningBytes: 96 * 1024,
        wsPoorBytes: 256 * 1024,
        senderPollMs: 5,
        metricsIntervalMs: 1000,
    });

    const HEADER_BYTES = 20;
    const FORMAT_PCM16_LE = 1;
    const FLAG_STEREO = 0x0001;
    const FLAG_FRAME_10MS = 0x0002;

    function encodeFrame(sequence, captureTimestampMs, sampleRate, pcm, flags) {
        if (!(pcm instanceof Int16Array)) throw new TypeError('pcm must be Int16Array');
        const packet = new ArrayBuffer(HEADER_BYTES + pcm.byteLength);
        const view = new DataView(packet);
        view.setUint8(0, 0x4d); // M
        view.setUint8(1, 0x55); // U
        view.setUint8(2, 1);
        view.setUint8(3, FORMAT_PCM16_LE);
        view.setUint32(4, sequence >>> 0, false);
        view.setUint32(8, Math.max(0, Math.round(captureTimestampMs)) >>> 0, false);
        view.setUint16(12, sampleRate, false);
        view.setUint16(14, pcm.length, false);
        view.setUint16(16, pcm.byteLength, false);
        view.setUint16(18, flags || 0, false);
        new Uint8Array(packet, HEADER_BYTES).set(new Uint8Array(pcm.buffer, pcm.byteOffset, pcm.byteLength));
        return packet;
    }

    class MicQualityMetrics {
        constructor() { this.reset(); }

        reset() {
            this.capturedFrames = 0;
            this.sentFrames = 0;
            this.droppedFrames = 0;
            this.uplinkDroppedOldFrames = 0;
            this.browserQueueFrames = 0;
            this.browserQueueMs = 0;
            this.browserQueuePeakMs = 0;
            this.wsBufferedAmount = 0;
            this.wsBufferedPeak = 0;
            this.clippedSamples = 0;
            this.totalSamples = 0;
            this.server = {};
        }

        observeSamples(pcm) {
            this.totalSamples += pcm.length;
            for (let i = 0; i < pcm.length; i++) {
                if (pcm[i] >= 32112 || pcm[i] <= -32112) this.clippedSamples++;
            }
        }

        observeSocket(socket) {
            const amount = socket && Number.isFinite(socket.bufferedAmount) ? socket.bufferedAmount : 0;
            this.wsBufferedAmount = amount;
            this.wsBufferedPeak = Math.max(this.wsBufferedPeak, amount);
        }

        mergeServer(metrics) { this.server = Object.assign({}, this.server, metrics || {}); }

        snapshot() {
            const combined = Object.assign({}, this.server, {
                capturedFrames: this.capturedFrames,
                sentFrames: this.sentFrames,
                droppedFrames: this.droppedFrames,
                uplinkDroppedOldFrames: this.uplinkDroppedOldFrames,
                browserQueueFrames: this.browserQueueFrames,
                browserQueueMs: this.browserQueueMs,
                browserQueuePeakMs: this.browserQueuePeakMs,
                wsBufferedAmount: this.wsBufferedAmount,
                wsBufferedPeak: this.wsBufferedPeak,
                clippedSamples: this.clippedSamples,
                totalSamples: this.totalSamples,
                clippingPercent: this.totalSamples ? (100 * this.clippedSamples / this.totalSamples) : 0,
            });
            const serverDrops = Math.max(
                Number(combined.lateFrames || 0),
                Number(combined.lateFramesDropped || 0)
            ) + Number(combined.serverDroppedFrames || 0);
            // Browser drops later appear as sequence gaps at the server; max()
            // avoids counting the same missing frame twice.
            const drops = Math.max(combined.droppedFrames, serverDrops);
            combined.dropPercent = 100 * drops / Math.max(1, combined.sentFrames + drops);
            combined.quality = qualityState(combined);
            return combined;
        }
    }

    class MicUplinkQueue {
        constructor(config, metrics) {
            this.config = Object.assign({}, CONFIG, config || {});
            this.metrics = metrics || new MicQualityMetrics();
            this.frames = [];
            this.nextSendDeadline = null;
            const sampleRate = Number(this.config.sampleRate || 8000);
            const realtimeBytes = Math.ceil(sampleRate * 2 * (this.config.hardQueueMs / 1000))
                + Math.ceil(this.config.hardQueueMs / this.config.frameMs) * HEADER_BYTES;
            this.wsSendBudgetBytes = Math.min(this.config.wsHealthyBytes, realtimeBytes);
        }

        enqueue(frame) {
            this.frames.push(frame);
            this.metrics.capturedFrames++;
            const hardFrames = Math.max(1, Math.floor(this.config.hardQueueMs / this.config.frameMs));
            while (this.frames.length > hardFrames) {
                this.frames.shift(); // real-time voice keeps the newest audio
                this.metrics.droppedFrames++;
                this.metrics.uplinkDroppedOldFrames++;
            }
            this.updateDepth();
        }

        sendOne(socket, nowMs) {
            this.metrics.observeSocket(socket);
            if (!socket || socket.readyState !== 1 || this.frames.length === 0) return false;
            if (socket.bufferedAmount >= this.wsSendBudgetBytes) {
                // The WebSocket's TCP queue cannot be edited, so keep only recent
                // not-yet-sent audio while its older bytes drain.
                while (this.frames.length * this.config.frameMs > this.config.targetQueueMs) {
                    this.frames.shift();
                    this.metrics.droppedFrames++;
                    this.metrics.uplinkDroppedOldFrames++;
                }
                this.updateDepth();
                return false;
            }
            if (this.nextSendDeadline !== null && nowMs < this.nextSendDeadline) return false;

            const frame = this.frames[0];
            try {
                socket.send(frame.packet);
            } catch (_) {
                return false;
            }
            this.frames.shift();
            this.metrics.sentFrames++;
            // A late JS callback never drains several frames in one turn.
            const candidate = this.nextSendDeadline === null
                ? nowMs + this.config.frameMs
                : this.nextSendDeadline + this.config.frameMs;
            this.nextSendDeadline = candidate > nowMs
                ? candidate
                : nowMs + this.config.frameMs;
            this.updateDepth();
            this.metrics.observeSocket(socket);
            return true;
        }

        clear() {
            this.frames.length = 0;
            this.nextSendDeadline = null;
            this.updateDepth();
        }

        updateDepth() {
            this.metrics.browserQueueFrames = this.frames.length;
            this.metrics.browserQueueMs = this.frames.length * this.config.frameMs;
            this.metrics.browserQueuePeakMs = Math.max(
                this.metrics.browserQueuePeakMs,
                this.metrics.browserQueueMs
            );
        }
    }

    function qualityState(m) {
        if (Object.prototype.hasOwnProperty.call(m, 'capturedFrames') && Number(m.capturedFrames) < 5) return 'good';
        const jitter = Number(m.uplinkJitterP95 || m.uplinkJitterMs || 0);
        const queue = Number(m.browserQueueMs || 0);
        const ws = Number(m.wsBufferedAmount || 0);
        const drops = Number(m.dropPercent || 0);
        const underruns = Number(m.pacerUnderruns || 0);
        const underrunPercent = 100 * underruns / Math.max(1, Number(m.rtpPacketsSent || m.sentFrames || 1));
        if (ws >= CONFIG.wsPoorBytes || queue >= 160 || drops >= 8 || underrunPercent >= 8) return 'critical';
        if (ws >= CONFIG.wsWarningBytes || queue >= 140 || jitter >= 60 || drops >= 4 || underrunPercent >= 4) return 'poor';
        if (ws >= CONFIG.wsHealthyBytes || queue >= 100 || jitter >= 30 || drops >= 1 || underrunPercent >= 1) return 'unstable';
        if (queue < 60 && jitter < 15 && drops < 0.1 && underrunPercent === 0) return 'excellent';
        return 'good';
    }

    function bufferedState(bytes) {
        if (bytes < CONFIG.wsHealthyBytes) return 'healthy';
        if (bytes < CONFIG.wsWarningBytes) return 'warning';
        if (bytes < CONFIG.wsPoorBytes) return 'poor';
        return 'critical';
    }

    function channelsFromFlags(flags) { return (Number(flags || 0) & FLAG_STEREO) !== 0 ? 2 : 1; }

    return {CONFIG, HEADER_BYTES, FORMAT_PCM16_LE, FLAG_STEREO, FLAG_FRAME_10MS, channelsFromFlags, encodeFrame, MicQualityMetrics, MicUplinkQueue, qualityState, bufferedState};
});
