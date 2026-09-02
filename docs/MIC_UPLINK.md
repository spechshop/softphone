# Microphone uplink pipeline

This pipeline protects real-time microphone audio from WebSocket/TCP head-of-line
blocking. Its quality label is an estimated transmission state, not MOS.

## Wire format (version 1)

Every numeric field is unsigned and in network byte order. PCM remains little-endian.

| Offset | Bytes | Field |
|---:|---:|---|
| 0 | 2 | magic `MU` |
| 2 | 1 | version (`1`) |
| 3 | 1 | format (`1` = PCM16-LE) |
| 4 | 4 | sequence |
| 8 | 4 | monotonic capture timestamp in ms relative to the browser audio session |
| 12 | 2 | sample rate |
| 14 | 2 | samples in this frame |
| 16 | 2 | payload length |
| 18 | 2 | flags (reserved, zero) |
| 20 | N | audio payload |

The server only accepts PCM16-LE frames whose sample count is exactly 20 ms,
whose payload is exactly `samples * 2`, and whose rate matches the WebSocket
session. Invalid frames are counted and discarded.

## Queue and pacing policy

- Browser target/soft/hard queue: 40/120/160 ms.
- On hard overflow, oldest frames are discarded.
- When the WebSocket is congested, sending pauses; the bounded queue only discards
  its oldest audio when it reaches the 160 ms hard limit.
- Diagnostic `bufferedAmount` bands: healthy below 32 KiB, warning 32--96 KiB,
  poor 96--256 KiB, critical above 256 KiB.
- Actual send backpressure starts at the smaller of 32 KiB and 160 ms of PCM plus
  headers (2,720 bytes at 8 kHz; 15,520 bytes at 48 kHz). This prevents a stable
  multi-second TCP backlog from being classified as usable merely because it is
  below 32 KiB.
- With a free WebSocket, the browser drains one frame normally and up to four per
  sender cycle while catching up, returning the queue to roughly 20--40 ms.
- Browser sending has no temporal pacing deadline; temporal pacing remains in the
  server jitter buffer and `RtpPacer`.
- Server jitter target: 60 ms / three frames (configurable from 40--100 ms with
  `MIC_JITTER_TARGET_MS`); maximum estimated frame age: 180 ms (configurable
  from 160--200 ms with `MIC_MAX_FRAME_AGE_MS`); hard capacity: ten frames.
- A late browser scheduler wake-up can emit a controlled two-to-four-frame catch-up
  burst. The server still emits at most one RTP packet per pacing deadline.
- On underrun, the server sends PCM16 silence through the existing codec encoder.
  It never fabricates a G.729, GSM, Opus, PCMA, or PCMU payload.
- Existing `rtpChannel` instances remain the owners of RTP sequence and timestamps.

Frame age is an excess-delay estimate. Browser and server clocks are not assumed to
be synchronized: the server subtracts the minimum observed transit time before
calculating age. The browser timestamp comes from its captured-audio sample cursor,
so a blocked main thread cannot collapse several capture times into one instant.

## Metrics and quality state

Browser metrics are aggregated once per second. Server logs are aggregated once per
ten seconds. Current quality uses a rolling 10-second window for
`recentDropPercent`, `recentJitterP95`, and `recentUnderrunPercent`. Whole-call
`totalDropPercent` remains diagnostic only and does not hold the realtime quality
state down after recovery. Drop causes remain separately visible as `browserDrops`,
`serverLateDrops`, `serverOverflowDrops`, and `sequenceGaps`.

Quality thresholds:

- Excellent: recent p95 jitter below 15 ms, queue below 60 ms, recent drops below 0.1%, no recent underrun.
- Good: conditions do not enter another state.
- Unstable: recent p95 jitter >= 30 ms, queue >= 100 ms, WS >= 32 KiB, recent drops >= 1%, or recent underruns >= 1%.
- Poor: recent p95 jitter >= 60 ms, queue >= 140 ms, WS >= 96 KiB, recent drops >= 4%, or recent underruns >= 4%.
- Critical: queue >= 160 ms, WS >= 256 KiB, recent drops >= 8%, or recent underruns >= 8%.

Underruns are scored as a percentage of paced packets, rather than by an absolute
session count. All state is reset at call change, hangup, microphone stop, and
server WebSocket close.

## Automated and laboratory tests

Run deterministic tests:

```bash
php tests/MicUplinkPipelineTest.php
node tests/frontend_mic_uplink_test.mjs
node tests/mic_uplink_bandwidth_benchmark.mjs
php tests/mic_uplink_stress.php
php tests/real_mic_uplink_burst.php
tests/netem_mic_uplink_profiles.sh
```

The frontend test includes a deterministic 60 ms scheduler pause and verifies
that a 100 ms browser queue returns to 40 ms in one bounded catch-up cycle with
zero WebSocket backlog and zero drops.

The profile script applies `tc netem` inside an unprivileged network namespace and
tests real WSS/local UDP/RTP construction. The bandwidth benchmark remains a
deterministic queue/link simulation, not a real-browser bandwidth test. For release
qualification, run real inbound/outbound calls and capture RTP send timestamps on
the deployment topology. Record the exact topology and commands with the release
results. Do not claim codec, perceptual, real-time 30-minute, production CPU/memory,
or production leak acceptance from the local/virtual tests alone.
