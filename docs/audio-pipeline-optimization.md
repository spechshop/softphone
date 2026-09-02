# Audio pipeline optimization report

Date: 2026-09-02  
Branch: `inbound`  
Before baseline: `66069c1`  
After: current working tree based on `647e9f1`

## Resulting pipeline

```text
Browser PCM (original rate/channels)
  -> audio.php pacing + binary IPC only
  -> MediaChannel::sendPcmToLeg(original rate/channels)
  -> one member conversion, packetization and codec/RTP

RTP
  -> MediaChannel/bridge decode
  -> binary PCM IPC (decoded rate/channels + monotonic timestamp)
  -> central :9966 parse/enqueue
  -> one persistent bounded worker per active stream
  -> one direct conversion to each distinct browser format + WebSocket push
```

`OpusConfig` no longer owns PCM conversion. `maxplaybackrate` and
`sprop-maxcapturerate` remain negotiation/codec settings and do not create an
intermediate PCM rate.

The central UDP receiver performs packet validation, stream/peer lookup,
aggregate metric accounting and bounded enqueue only. Mix, source buffering,
resample and WebSocket push run in the stream worker. Queues and source buffers
are bounded in milliseconds and discard oldest audio.

## Resample validation

`tests/AudioPipelineArchitectureTest.php` verifies:

| Route | Result |
|---|---:|
| 8k -> 8k | 0 resamples |
| 16k -> 16k | 0 resamples |
| 24k -> 24k | 0 resamples |
| 48k -> 48k | 0 resamples |
| 8k -> 48k | 1 direct resample |
| 48k -> 8k | 1 direct resample |
| 24k -> 48k | 1 direct resample |

For the former `8k -> 24k -> 48k` path, a 120 ms mono batch changed from two
logical resamples and 7,680 input bytes processed to one direct resample and
1,920 input bytes processed. That is 2 -> 1 calls per batch and 75% fewer input
bytes presented to resamplers.

## Real receiver stress

Command: `php tests/real_audio_playback_stress.php`

The run used binary UDP IPC, PCM16/8k mono, 20 ms input frames, the diagnostic
interval forced to 1 second, and the default 120 ms WebSocket batch. Every
stream received audio.

| Streams | Packets | CPU/core | RSS peak | IPC p95 | IPC p99 | Receiver p95 | Queue p95 | Peak | Drops | Resamples |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 10 | 1,000 | 9.0% | 39.7 MB | 0.76 ms | 0.76 ms | 0.20 ms | 20.7 ms | 2 | 0 | 0 |
| 30 | 3,000 | 19.9% | 41.8 MB | 2.08 ms | 2.08 ms | 0.17 ms | 22.2 ms | 2 | 0 | 0 |
| 50 | 5,000 | 28.5% | 44.0 MB | 2.72 ms | 2.72 ms | 0.22 ms | 22.4 ms | 2 | 0 | 0 |
| 100 | 10,000 | 38.7% | 49.0 MB | 4.27 ms | 4.27 ms | 0.60 ms | 26.9 ms | 2 | 0 | 0 |

The one-second metric interval deliberately overstates diagnostic CPU cost;
production defaults to ten seconds and samples one of every four timing
observations. Timing samples use fixed 512-entry rings, avoiding per-frame
`array_shift()` and unbounded diagnostic memory.

For the duplicate-resample workload at 100 streams (`8k -> 24k -> 48k` before,
direct `8k -> 48k` after), the old baseline measured a median 58.5% of one core
over three runs. The final direct path measured 53.7% in the same harness, an
8.2% reduction, with zero drops. Equal-rate `8k -> 8k` reports zero resamples.

After closing the 100-stream run, the audio server returned to 14 file
descriptors from a baseline of 14. The multi-tier run returned from 49.0 MB peak
to 42.3 MB RSS. The separate 100-cycle virtual lifecycle test reported 0.0 MB
growth.

## Codec and functional validation

- `test_media_channel_member_ptime.php`: PCMA, PCMU, G729, GSM, L16, ptime,
  accumulation, RTP timestamps and cleanup.
- `tests/OpusSupportTest.php`: Opus mono/stereo, direct 48 kHz PCM transport,
  fmtp separation, bitrate, ptime and signal-frequency survival.
- `tests/OpusOutboundNegotiationTest.php`: outbound negotiation through
  MediaChannel and encoder cleanup.
- `tests/real_mic_uplink_burst.php`: real WSS -> binary IPC -> PCMA/RTP path;
  10 packets, 20.04 ms average pacing, 21.29 ms p95/p99, continuous RTP
  sequence/timestamps.
- `tests/MicUplinkPipelineTest.php`: bounded jitter queue, duplicates, late
  drops, pacing, underrun and lifecycle.

These tests validate encoded/decoded signals and real local sockets. A final
subjective listening pass against the production SIP providers is still a lab
release step; it cannot be performed by the automated container.

## UDP versus Unix datagram

Five runs of 20,000 sequential 320-byte PCM datagrams:

| Transport | Throughput median | CPU median | Latency p95 | Latency p99 | Drops | Hot-path syscalls |
|---|---:|---:|---:|---:|---:|---:|
| UDP loopback | 98,883 pkt/s | 202.1 ms | 5.2 us | 7.0 us | 0 | 2/packet |
| Unix DGRAM | 112,640 pkt/s | 177.6 ms | 3.7 us | 5.2 us | 0 | 2/packet |

Unix DGRAM was faster in the isolated microbenchmark, but it was not migrated:
the end-to-end stream workload is dominated by conversion/WebSocket work, and
production still needs a defined socket path owner, permissions, stale-file
cleanup and rolling compatibility. UDP `127.0.0.1:9966` remains active until an
end-to-end canary confirms the microbenchmark advantage survives those costs.

The binary v1 header remains backward-readable alongside legacy separator
packets. It provides payload collision safety and monotonic transit latency;
the compatibility decoder can be removed only after all media processes are
rolled forward.

No shared memory was introduced and no `libspech` file was modified.

## Remaining acceptance boundary

The installed `MediaChannel::onReceive()` callback exposes raw RTP and runs
before MediaChannel's internal PCM decode. Because this task explicitly forbids
changes in `libspech`, the browser copy still uses independent stateful Opus and
G.729 decoders in the application media bridge; GSM reuses the transient PCM
already exposed by MediaChannel. `audio.php` performs no codec decode or encode.
Removing the remaining duplicate RTP decode requires a future decoded-PCM hook
from `libspech` and must not be emulated with another resample in `audio.php`.

For that reason, and because subjective provider listening is still pending,
the strict end-to-end release acceptance criterion is not claimed by this
automated run even though the bridge/IPC implementation and repeatable tests
are in place.
