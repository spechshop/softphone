# Mic uplink implementation report

Date: 2026-09-02. Branch: `inbound`.

This report distinguishes deterministic/local evidence from release qualification.
The implementation is present and its core pacing property is proven locally, but
the release is **not yet fully qualified** because real human calls, real-browser
bandwidth shaping, and a wall-clock 30-minute call require deployment credentials,
endpoints, and operators not available in this workspace.

1. **Root cause.** The browser called `WebSocket.send()` from the AudioWorklet
   message handler. `audio.php` immediately relayed each binary message to UDP, and
   the media handlers encoded and built RTP immediately. TCP head-of-line recovery
   could therefore turn 20 ms capture spacing into an RTP burst. There was no age,
   sequence, backpressure, or mic-side pacing information.

2. **Old architecture.** `mic -> AudioWorklet -> PCM16/20ms -> immediate WS send ->
   immediate audio.php UDP -> immediate encode/buildAudioPacket -> RTP`.

3. **New architecture.** `mic -> AudioWorklet -> PCM16/20ms + binary metadata ->
   bounded recent-first browser queue -> deadline sender/backpressure -> WS/TCP ->
   per-mic validated jitter buffer -> monotonic deadline pacer -> local UDP ->
   existing codec/rtpChannel -> RTP`. `libspech` was not changed.

4. **Binary protocol.** Fixed 20-byte header: magic `MU` (2), version (1), format
   (1), sequence (4), relative monotonic capture ms (4), sample rate (2), samples
   (2), payload length (2), flags (2), then PCM16-LE. All header numbers use network
   byte order. Version 1/format 1 is PCM16-LE; the format field reserves PCMA/Opus
   evolution. Exact validation is documented in `MIC_UPLINK.md`.

5. **Queue strategy.** Browser target/soft/hard values are 60/120/160 ms. Hard
   overflow removes the oldest frame. During socket congestion, unsent audio is
   trimmed to the newest 60 ms. The server targets three frames/60 ms and is capped
   at ten frames.

6. **Thresholds.** Maximum estimated server frame age is 180 ms. Jitter target is
   configurable 40--100 ms (`MIC_JITTER_TARGET_MS`); age is configurable 160--200 ms
   (`MIC_MAX_FRAME_AGE_MS`). Browser and quality thresholds are constants in
   `js/mic-uplink.js`.

7. **`bufferedAmount`.** Diagnostic states are `<32 KiB`, `32--96 KiB`,
   `96--256 KiB`, and `>256 KiB`. Sending stops earlier at `min(32 KiB, 160 ms of
   PCM+headers)`: 2,720 bytes at 8 kHz and 15,520 bytes at 48 kHz. This matters
   because 32 KiB is already about two seconds of raw 8 kHz PCM.

8. **Server jitter buffer.** One `MicUplinkSession` per microphone WebSocket/stream,
   ordered by wrap-aware sequence number. It counts duplicate, out-of-order,
   missing, expired, invalid, and overflow frames. Age uses excess transit over the
   minimum observed transit, so browser/server wall clocks need not match.

9. **RTP pacer.** One coroutine per mic session. It uses `hrtime()` deadlines, emits
   at most one frame per deadline, and preserves `nextDeadline += 20 ms` under normal
   scheduler jitter. After a whole missed slot it schedules from `now + 20 ms`, so
   it never emits a catch-up burst.

10. **Drop policy.** Browser: oldest unsent frame at overflow/congestion. Server:
    frames older than the 180 ms excess-age threshold and oldest frames above ten.
    New audio is always preferred over stale audio.

11. **Underrun policy.** The server generates PCM16 silence and passes it through
    the existing codec encoder. It does not fabricate encoded PCMA/PCMU/G.729/GSM/
    Opus bytes. Existing `rtpChannel` logic remains responsible for RTP sequence and
    timestamp progression.

12. **Metrics.** Capture/send/drop, browser queue/current/peak, WebSocket current/
    peak, invalid/duplicate/out-of-order/late/server drop, jitter average/p95, age
    average/p95, server buffer/current/peak, underrun, paced packets, RTP gap average/
    p95/p99/max, clipped/total samples and clipping percentage. Browser aggregation
    is one second; technical logging is ten seconds.

13. **Quality algorithm.** Estimated transmission state, explicitly not MOS.
    Excellent/Good/Unstable/Poor/Critical use p95 jitter, queue depth, WS buffer,
    drop percentage, and underrun percentage. Browser drops and their corresponding
    server sequence gaps use `max`, not addition, to avoid double counting.

14. **Frontend.** A compact `Transmissão • state ▂▄▆█` row sits below call controls.
    Expansion shows Jitter, Fila, Drops, and WS buffer. State text always accompanies
    green/yellow/orange/red. The same capture component is called from inbound and
    outbound lifecycles. AGC remains enabled by default and now has a persisted A/B
    checkbox; clipping is measured after `micGainNode`.

15. **Files changed.** `audio.php`; `js/mic-uplink.js`; the five mic/pacer helpers
    under `plugins/Utils/helpers`; `plugins/Request/modules/includes/head.html`;
    `plugins/Request/pages/default.html`; two documentation files; and five focused
    six focused test/benchmark scripts under `tests`. No `libspech` file changed.

16. **Automated tests.** `MicUplinkPipelineTest.php` covers wire validation,
    ordering, initial reordering, duplicate, late/overflow drop, sequence wrap,
    burst, deadlines, underrun, metrics, quality, and cleanup.
    `frontend_mic_uplink_test.mjs` covers header bytes, bounded/recent-first queue,
    backpressure, sender pacing, clipping/reset, all five states, panel fields, AGC,
    and shared inbound/outbound wiring. The complete existing local regression suite
    also passed.

17. **Burst result.** Deterministic 10-frame simultaneous arrival: output gaps were
    `20,20,20,20,20,20,20,20,20 ms`; avg/p95/p99/max all 20 ms. Real WSS ->
    `audio.php` -> UDP bridge input -> PCMA -> existing `rtpChannel`: 10 packets,
    avg 20.09 ms, p95/p99/max 20.41 ms, min 19.23 ms on the final unshaped run.
    RTP sequence was continuous and timestamp advanced exactly 160 per packet.

18. **`tc netem` result.** Tests ran inside an unprivileged isolated network
    namespace, shaping only uplink TCP/8889 and leaving local bridge UDP unshaped:

    | Profile | Uplink impairment | RTP avg | p95/p99 | min | max |
    |---|---|---:|---:|---:|---:|
    | A | 50 ms, jitter 10 ms | 20.08 | 20.83 | 19.28 | 20.83 |
    | B | 100 ms, jitter 30 ms | 20.04 | 20.83 | 19.29 | 20.83 |
    | C | 150 ms, jitter 60 ms | 20.11 | 20.76 | 19.17 | 20.76 |
    | D | 250 ms, jitter 100 ms | 20.11 | 20.79 | 19.34 | 20.79 |
    | E | slot/burst 200--500 ms | 20.03 | 21.49 | 18.44 | 21.49 |

    `tc -s` confirmed packets traversed each netem qdisc. These are local protocol
    integration results, not perceptual calls.

19. **Bandwidth results.** Deterministic 60-second PCM16/8 kHz link simulation,
    including the 20-byte header (not a real-browser/netem bandwidth result):

    | kbps | old backlog | new WS peak | new queue peak | drops |
    |---:|---:|---:|---:|---:|
    | 512 | 0 ms | 0.3 KiB | 20 ms | 0% |
    | 256 | 0 ms | 0.3 KiB | 20 ms | 0% |
    | 128 | 3,751 ms | 3.0 KiB | 80 ms | 5.5% |
    | 64 | 67,501 ms | 3.0 KiB | 80 ms | 52.6% |
    | 32 | 195,001 ms | 3.0 KiB | 80 ms | 76.2% |

    At insufficient bandwidth, dropping is intentional; the implementation does
    not claim usable voice quality at 32/64 kbps with PCM16.

20. **RTP gaps.** See items 17--18. The strongest objective evidence is profile E:
    TCP packets were slotted into 200--500 ms delivery bursts while RTP remained
    avg 20.03 ms, p95/p99 21.49 ms, max 21.49 ms.

21. **Drops.** Zero in simulated 512/256 kbps; 166/1,577/2,283 browser frames in
    60 seconds at 128/64/32 kbps. Burst/netem tests intentionally did not assert zero
    late drops because age policy may replace stale burst frames with paced silence.

22. **Queue peak.** 20 ms when capacity was sufficient and 80 ms in the constrained
    deterministic bandwidth simulation; configured absolute hard ceiling is 160 ms.

23. **`bufferedAmount` peak.** About 0.3 KiB at >=256 kbps and 3.0 KiB at
    128/64/32 kbps in the deterministic simulation. Old unbounded peaks were 58.9,
    527.5, and 761.8 KiB respectively.

24. **CPU.** Virtual 60-second pipeline stress wall time: 10 sessions/30k packets
    276.8 ms; 30/90k 827.2 ms; 50/150k 1,379.9 ms. This is algorithm cost, not
    production Swoole CPU utilization.

25. **Memory.** Virtual stress: current 4/6/6 MiB for 10/30/50 sessions; observed
    process peak up to 10 MiB. This is not a production RSS acceptance result.

26. **30-minute test.** One virtual session processed 90,000 deadlines in 876.5 ms,
    remained bounded, and used 4 MiB current/10 MiB process peak. A real wall-clock
    30-minute SIP call with network variation remains pending.

27. **Leak tests.** One hundred virtual create/audio/close cycles returned from
    4.0 MiB to 4.0 MiB (0.0 MiB delta). Existing frontend inbound lifecycle test also
    passed 50 cycles. One hundred real browser/SIP cycles and production RSS remain
    pending.

28. **Real perceptual test.** Pending: no human microphone, remote listening endpoint,
    Wi-Fi/4G path, or SIP credentials were available. No subjective improvement is
    claimed from automated tests.

29. **Before/after.** Before, one callback forwarded every arrived frame and a burst
    had effectively 0 ms relay gaps; the deterministic bandwidth model accumulated
    up to 195 seconds. After, both deterministic and real WSS/RTP tests show one
    packet around every 20 ms, while bounded queues cap memory/time and discard stale
    audio. A packet capture from the deployed pre-change version is still needed for
    a production baseline comparison.

30. **Remaining limitations.** Uplink transport remains PCM16; PCMA transport was
    deferred to avoid mixing transcoding risk into pacing. Real inbound/outbound
    calls for PCMA, PCMU, G.729, GSM, and Opus; mute/gain/reconnect behavior in real
    browsers; AGC recordings; real-browser 512--32 kbps shaping; production 10/30/50
    session CPU/RSS; 30-minute wall-clock drift; 100 real lifecycle cycles; Wi-Fi/
    cellular perception; and deployed before/after packet captures are release gates.

## Conclusion status

The principal engineering property is proven locally: irregular/burst WSS input is
converted to approximately 20 ms RTP spacing without modifying `libspech`. The full
task must not be marked complete until the pending real-call and production-like
qualification in items 19 and 24--30 is executed and attached to this report.
