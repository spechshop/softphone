(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.SpechOpus = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const DEFAULTS = Object.freeze({
        profile: 'standard', channels: 1, stereo: false,
        maxPlaybackRate: 24000, maxCaptureRate: 24000,
        maxAverageBitrate: 32000, fec: true, ptime: 20
    });
    const PRESETS = Object.freeze({
        economy: Object.freeze({profile: 'economy', channels: 1, stereo: false, maxPlaybackRate: 24000, maxCaptureRate: 24000, maxAverageBitrate: 24000, fec: true, ptime: 40}),
        standard: DEFAULTS,
        high: Object.freeze({profile: 'high', channels: 1, stereo: false, maxPlaybackRate: 48000, maxCaptureRate: 48000, maxAverageBitrate: 64000, fec: true, ptime: 20}),
        stereo: Object.freeze({profile: 'stereo', channels: 2, stereo: true, maxPlaybackRate: 48000, maxCaptureRate: 48000, maxAverageBitrate: 96000, fec: true, ptime: 20})
    });
    const RATES = Object.freeze([8000, 12000, 16000, 24000, 48000]);
    const BITRATES = Object.freeze([16000, 24000, 32000, 48000, 64000, 96000]);
    const PTIMES = Object.freeze([10, 20, 40, 60]);

    function normalize(value) {
        const input = value && typeof value === 'object' ? value : {};
        const allowed = (candidate, values, fallback) => values.includes(Number(candidate)) ? Number(candidate) : fallback;
        const stereo = input.stereo === undefined
            ? Number(input.channels) === 2
            : (input.stereo === true || input.stereo === 1 || input.stereo === '1');
        return {
            profile: ['economy', 'standard', 'high', 'stereo', 'custom'].includes(input.profile) ? input.profile : 'standard',
            channels: stereo ? 2 : 1,
            stereo,
            maxPlaybackRate: allowed(input.maxPlaybackRate, RATES, DEFAULTS.maxPlaybackRate),
            maxCaptureRate: allowed(input.maxCaptureRate, RATES, DEFAULTS.maxCaptureRate),
            maxAverageBitrate: allowed(input.maxAverageBitrate ?? input.bitrate, BITRATES, DEFAULTS.maxAverageBitrate),
            fec: input.fec === undefined ? true : !(input.fec === false || input.fec === 0 || input.fec === '0'),
            ptime: allowed(input.ptime, PTIMES, DEFAULTS.ptime)
        };
    }

    function resolveCapture(requested, trackSettings) {
        const config = normalize(requested);
        const actualChannels = config.channels === 2 && Number(trackSettings?.channelCount) === 2 ? 2 : 1;
        return {
            config: {...config, channels: actualChannels, stereo: actualChannels === 2},
            requestedChannels: config.channels,
            actualChannels,
            fallback: config.channels === 2 && actualChannels === 1
        };
    }

    return {DEFAULTS, PRESETS, RATES, BITRATES, PTIMES, normalize, resolveCapture};
});
