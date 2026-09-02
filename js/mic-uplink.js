(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.SpechMicUplink = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const CONFIG = Object.freeze({
        frameMs: 20,
        targetQueueMs: 40,
        softQueueMs: 120,
        hardQueueMs: 160,
        maxCatchUpFrames: 4,
        wsHealthyBytes: 32 * 1024,
        wsWarningBytes: 96 * 1024,
        wsPoorBytes: 256 * 1024,
        senderPollMs: 5,
        metricsIntervalMs: 1000,
        recentWindowMs: 10 * 1000,
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
        constructor(clock) {
            this.clock = typeof clock === 'function'
                ? clock
                : () => (typeof performance !== 'undefined' ? performance.now() : Date.now());
            this.reset();
        }

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
            this.counterSamples = [];
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

        snapshot(nowMs) {
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

            const counters = dropCounters(combined);
            Object.assign(combined, counters);
            combined.totalDrops = aggregateDrops(counters);
            const totalSent = Math.max(0, Number(combined.sentFrames || combined.receivedFrames || 0));
            combined.totalDropPercent = percent(combined.totalDrops, totalSent + combined.totalDrops);

            const recent = this.recentCounters(Number.isFinite(nowMs) ? nowMs : this.clock(), counters);
            combined.recentBrowserDrops = recent.browserDrops;
            combined.recentServerLateDrops = recent.serverLateDrops;
            combined.recentServerOverflowDrops = recent.serverOverflowDrops;
            combined.recentSequenceGaps = recent.sequenceGaps;
            combined.recentDrops = aggregateDrops(recent);
            const recentSent = recent.sentFrames > 0 ? recent.sentFrames : recent.receivedFrames;
            combined.recentDropPercent = percent(combined.recentDrops, recentSent + combined.recentDrops);
            combined.recentUnderruns = recent.pacerUnderruns;
            combined.recentUnderrunPercent = percent(recent.pacerUnderruns, recent.rtpPacketsSent);
            // Compatibility for existing diagnostics: dropPercent now means the
            // current rolling window, never the whole call.
            combined.dropPercent = combined.recentDropPercent;
            combined.quality = qualityState(combined);
            return combined;
        }

        recentCounters(nowMs, counters) {
            const point = Object.assign({at: nowMs}, counters);
            const last = this.counterSamples[this.counterSamples.length - 1];
            if (last && last.at === nowMs) this.counterSamples[this.counterSamples.length - 1] = point;
            else this.counterSamples.push(point);

            const cutoff = nowMs - CONFIG.recentWindowMs;
            let baselineIndex = -1;
            for (let i = 0; i < this.counterSamples.length; i++) {
                if (this.counterSamples[i].at <= cutoff) baselineIndex = i;
                else break;
            }
            const baseline = baselineIndex >= 0 ? this.counterSamples[baselineIndex] : {};
            if (baselineIndex > 0) this.counterSamples.splice(0, baselineIndex);

            const delta = {};
            for (const key of COUNTER_KEYS) {
                delta[key] = Math.max(0, Number(counters[key] || 0) - Number(baseline[key] || 0));
            }
            return delta;
        }
    }

    const COUNTER_KEYS = Object.freeze([
        'browserDrops', 'serverLateDrops', 'serverOverflowDrops', 'sequenceGaps',
        'sentFrames', 'receivedFrames', 'pacerUnderruns', 'rtpPacketsSent',
    ]);

    function dropCounters(m) {
        const serverLateDrops = Math.max(0, Number(m.lateFramesDropped || 0));
        return {
            browserDrops: Math.max(0, Number(m.droppedFrames || 0), Number(m.uplinkDroppedOldFrames || 0)),
            serverLateDrops,
            serverOverflowDrops: Math.max(0, Number(m.serverDroppedFrames || 0)),
            sequenceGaps: Math.max(0, Number(m.lateFrames || 0) - serverLateDrops),
            sentFrames: Math.max(0, Number(m.sentFrames || 0)),
            receivedFrames: Math.max(0, Number(m.receivedFrames || 0)),
            pacerUnderruns: Math.max(0, Number(m.pacerUnderruns || 0)),
            rtpPacketsSent: Math.max(0, Number(m.rtpPacketsSent || 0)),
        };
    }

    function aggregateDrops(counters) {
        const serverDrops = counters.serverLateDrops + counters.serverOverflowDrops + counters.sequenceGaps;
        // Browser drops normally reappear as server sequence gaps. max() keeps
        // the aggregate useful without hiding the individual diagnostic causes.
        return Math.max(counters.browserDrops, serverDrops);
    }

    function percent(numerator, denominator) {
        return 100 * Math.max(0, Number(numerator || 0)) / Math.max(1, Number(denominator || 0));
    }

    class MicUplinkQueue {
        constructor(config, metrics) {
            this.config = Object.assign({}, CONFIG, config || {});
            this.metrics = metrics || new MicQualityMetrics();
            this.frames = [];
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

        sendOne(socket) {
            this.metrics.observeSocket(socket);
            if (!socket || socket.readyState !== 1 || this.frames.length === 0) return false;
            if (socket.bufferedAmount >= this.wsSendBudgetBytes) {
                // Hold while TCP drains. enqueue() keeps this queue bounded and
                // discards the oldest audio only when the hard limit is reached.
                return false;
            }

            const frame = this.frames[0];
            try {
                socket.send(frame.packet);
            } catch (_) {
                return false;
            }
            this.frames.shift();
            this.metrics.sentFrames++;
            this.updateDepth();
            this.metrics.observeSocket(socket);
            return true;
        }

        drain(socket) {
            this.metrics.observeSocket(socket);
            if (!socket || socket.readyState !== 1 || this.frames.length === 0) return 0;
            if (socket.bufferedAmount >= this.wsSendBudgetBytes) return 0;

            const targetFrames = Math.max(1, Math.ceil(this.config.targetQueueMs / this.config.frameMs));
            const queuedFrames = this.frames.length;
            let sendLimit = 1;
            if (queuedFrames > targetFrames) {
                // Recover from a delayed browser callback without turning the
                // entire bounded queue into one large TCP burst.
                sendLimit = Math.min(
                    Math.max(1, Number(this.config.maxCatchUpFrames) || 1),
                    Math.max(2, queuedFrames - targetFrames)
                );
            }

            let sent = 0;
            while (sent < sendLimit && this.sendOne(socket)) sent++;
            return sent;
        }

        clear() {
            this.frames.length = 0;
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
        const jitter = Number(m.recentJitterP95 ?? m.uplinkJitterP95 ?? m.uplinkJitterMs ?? 0);
        const queue = Number(m.browserQueueMs || 0);
        const ws = Number(m.wsBufferedAmount || 0);
        const drops = Number(m.recentDropPercent ?? m.dropPercent ?? 0);
        const underrunPercent = Number(m.recentUnderrunPercent
            ?? percent(m.pacerUnderruns, m.rtpPacketsSent || m.sentFrames));
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
