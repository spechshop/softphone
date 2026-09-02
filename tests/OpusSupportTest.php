<?php

declare(strict_types=1);

require __DIR__ . '/../libspech/plugins/autoloader.php';
require_once __DIR__ . '/../plugins/Utils/helpers/OpusConfig.php';
require_once __DIR__ . '/../plugins/Utils/helpers/SdpHelper.php';

use helpers\utils\OpusConfig;
use helpers\utils\SdpHelper;
use libspech\Rtp\MediaChannel;
use Swoole\Coroutine;

function opusAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function opusSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true)
            . ' actual=' . var_export($actual, true));
    }
}

final class OpusCaptureSocket extends SocketMutable
{
    /** @var list<string> */
    public array $packets = [];
    /** @var list<float> */
    public array $sentAtMs = [];
    public function __construct() {}
    public function __destruct() {}
    public function isClosed(): bool { return false; }
    public function sendto(string $addr, int $port, string $data): int|false
    {
        $this->packets[] = $data;
        $this->sentAtMs[] = hrtime(true) / 1e6;
        return strlen($data);
    }
}

/** @return array{pt:int,sequence:int,timestamp:int,payload:string} */
function decodeOpusRtp(string $packet): array
{
    $h = unpack('Cfirst/Csecond/nsequence/Ntimestamp/Nssrc', substr($packet, 0, 12));
    return [
        'pt' => $h['second'] & 0x7f,
        'sequence' => $h['sequence'],
        'timestamp' => $h['timestamp'],
        'payload' => substr($packet, 12),
    ];
}

/** @param array<string,mixed> $config @return array{0:MediaChannel,1:OpusCaptureSocket,2:string} */
function opusMedia(array $config, int $dtmfRate = 48000, int $opusPt = 111, int $dtmfPt = 101): array
{
    $socket = new OpusCaptureSocket();
    $reflection = new ReflectionClass(MediaChannel::class);
    /** @var MediaChannel $media */
    $media = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('onDestructCallable')->setValue($media, static function (): void {});
    $media->socket = $socket;
    $media->setPacketTime((int)$config['ptime']);
    $mapper = [
        $opusPt => 'OPUS/48000/' . (int)$config['channels'],
        $dtmfPt => "telephone-event/{$dtmfRate}/1",
    ];
    $media->codecMapper = $mapper;
    $media->txCodecMapper = $mapper;
    $media->rxCodecMapper = $mapper;
    $media->registerPtCodecs($mapper);
    if ($dtmfPt !== 101) unset($media->ptCodecs[101], $media->ptFrequencies[101]);
    $media->addMember([
        'address' => '127.0.0.1', 'port' => 39000, 'leg' => 'a',
        'codec' => 'OPUS', 'pt' => $opusPt, 'txPt' => $opusPt, 'rxPt' => $opusPt,
        'frequency' => 48000, 'channels' => $config['channels'], 'ptime' => $config['ptime'],
        'config' => OpusConfig::mediaConfig($config),
        'txCodecMapper' => $mapper, 'rxCodecMapper' => $mapper,
    ]);
    $id = '127.0.0.1:39000';
    $media->members[$id]['opusEncoderApplied'] = OpusConfig::applyEncoder(
        $media->members[$id]['opusEncoder'],
        $config,
    );
    return [$media, $socket, $id];
}

function pcmSine(int $samples, int $rate, float $frequency, float $amplitude = 0.6): string
{
    $pcm = '';
    for ($i = 0; $i < $samples; $i++) {
        $value = (int)round(sin(2 * M_PI * $frequency * $i / $rate) * 32767 * $amplitude);
        $pcm .= pack('v', $value & 0xffff);
    }
    return $pcm;
}

function stereoSines(int $samples, int $rate, float $leftFrequency, float $rightFrequency): string
{
    $left = pcmSine($samples, $rate, $leftFrequency);
    $right = pcmSine($samples, $rate, $rightFrequency);
    $pcm = '';
    for ($i = 0; $i < $samples; $i++) {
        $pcm .= substr($left, $i * 2, 2) . substr($right, $i * 2, 2);
    }
    return $pcm;
}

function signed16(string $bytes): int
{
    $value = unpack('v', $bytes)[1];
    return $value >= 0x8000 ? $value - 0x10000 : $value;
}

function zeroCrossFrequency(string $interleaved, int $channel, int $channels, int $rate): float
{
    $frames = intdiv(strlen($interleaved), 2 * $channels);
    $crossings = 0;
    $previous = signed16(substr($interleaved, $channel * 2, 2));
    for ($frame = 1; $frame < $frames; $frame++) {
        $current = signed16(substr($interleaved, ($frame * $channels + $channel) * 2, 2));
        if (($previous < 0 && $current >= 0) || ($previous >= 0 && $current < 0)) $crossings++;
        $previous = $current;
    }
    return ($crossings * $rate) / max(1, 2 * $frames);
}

// Canonical defaults, presets and strict validation/backward compatibility.
$defaults = OpusConfig::normalize(null);
opusSame(1, $defaults['channels'], 'legacy/default Opus must be mono');
opusSame(32000, $defaults['maxAverageBitrate'], 'default bitrate');
opusSame(24000, $defaults['maxCaptureRate'], 'default capture bandwidth');
opusSame(20, $defaults['ptime'], 'default ptime');
opusSame(32000, OpusConfig::normalize(['bitrate' => 123456])['maxAverageBitrate'], 'invalid bitrate accepted');
opusSame(20, OpusConfig::normalize(['ptime' => 30])['ptime'], 'unsupported Opus ptime accepted');

$codec = ['name' => 'OPUS', 'pt' => 111, 'rate' => 48000, 'channels' => 1];
$monoSdp = SdpHelper::buildLocalSdp('192.0.2.10', 58658, $codec, null, $defaults);
opusAssert(str_contains($monoSdp, 'm=audio 58658 RTP/AVP 111 101'), 'mono m-line/PT');
opusAssert(str_contains($monoSdp, 'a=rtpmap:111 OPUS/48000/2'), 'mono must keep opus/48000/2');
opusAssert(str_contains($monoSdp, 'a=fmtp:111 maxplaybackrate=24000;sprop-maxcapturerate=24000;maxaveragebitrate=32000;useinbandfec=1;stereo=0;sprop-stereo=0'), 'mono fmtp');
opusAssert(str_contains($monoSdp, 'a=rtpmap:101 telephone-event/48000'), 'Opus offer DTMF clock');
opusAssert(str_contains($monoSdp, 'a=ptime:20'), 'mono ptime');

$stereo = OpusConfig::presets()['stereo'];
$stereoSdp = SdpHelper::buildLocalSdp('192.0.2.10', 58658, $codec, null, $stereo);
opusAssert(str_contains($stereoSdp, 'a=rtpmap:111 OPUS/48000/2'), 'stereo rtpmap');
opusAssert(str_contains($stereoSdp, 'maxaveragebitrate=96000;useinbandfec=1;stereo=1;sprop-stereo=1'), 'stereo fmtp');
$fecOff = OpusConfig::normalize(['fec' => false]);
opusAssert(str_contains(SdpHelper::buildLocalSdp('192.0.2.10', 1, $codec, null, $fecOff), 'useinbandfec=0'), 'FEC off SDP');

// Remote parsing, dynamic Opus PT, typed fmtp, DTMF clock and ptime.
$remote = SdpHelper::parseRemoteSdp([
    'c' => ['IN IP4 198.51.100.20'],
    'm' => ['audio 40000 RTP/AVP 96 110'],
    'a' => [
        'rtpmap:96 opus/48000/2',
        'fmtp:96 maxplaybackrate=24000;sprop-maxcapturerate=48000;maxaveragebitrate=64000;useinbandfec=1;stereo=1;sprop-stereo=1',
        'rtpmap:110 telephone-event/8000', 'fmtp:110 0-15', 'ptime:40',
    ],
]);
$chosen = SdpHelper::chooseCodec($remote['codecs']);
opusSame(96, $chosen['pt'], 'dynamic Opus PT changed');
opusSame(true, $chosen['fmtp_parsed']['stereo'], 'typed stereo fmtp');
opusSame(8000, $remote['telephone_event']['rate'], 'remote DTMF clock');
opusSame(40, $remote['ptime'], 'remote ptime');
$multipleDtmf = SdpHelper::parseRemoteSdp([
    'm' => ['audio 40000 RTP/AVP 96 109 110'],
    'a' => ['rtpmap:96 opus/48000/2', 'rtpmap:109 telephone-event/48000', 'rtpmap:110 telephone-event/8000'],
]);
opusSame(109, $multipleDtmf['telephone_event']['pt'], 'remote DTMF preference order');
opusSame(48000, $multipleDtmf['telephone_event']['rate'], 'remote preferred DTMF clock');
$effective = OpusConfig::negotiate($stereo, $chosen['fmtp_parsed'], $remote['ptime']);
opusSame(2, $effective['channels'], 'stereo intersection');
opusSame(24000, $effective['maxCaptureRate'], 'remote playback must constrain local capture');
opusSame(64000, $effective['maxAverageBitrate'], 'bitrate intersection');
opusSame(40, $effective['ptime'], 'ptime intersection');
$answer = SdpHelper::buildLocalSdp('192.0.2.10', 50000, $chosen, $remote['telephone_event'], $effective, $effective['ptime']);
opusAssert(str_contains($answer, 'm=audio 50000 RTP/AVP 96 110'), 'answer did not preserve PTs');
opusAssert(str_contains($answer, 'a=rtpmap:110 telephone-event/8000'), 'answer forced DTMF/48000');
$answerWithoutDtmf = SdpHelper::buildLocalSdp('192.0.2.10', 50000, $chosen, null, $effective, 40, true);
opusAssert(str_contains($answerWithoutDtmf, "m=audio 50000 RTP/AVP 96\r\n"), 'answer added unoffered DTMF PT');
opusAssert(!str_contains($answerWithoutDtmf, 'telephone-event'), 'answer added unoffered telephone-event');

// Non-Opus SDP keeps the legacy DTMF clock and packet time.
foreach ([
    ['name' => 'PCMA', 'pt' => 8, 'rate' => 8000, 'channels' => 1],
    ['name' => 'PCMU', 'pt' => 0, 'rate' => 8000, 'channels' => 1],
    ['name' => 'G729', 'pt' => 18, 'rate' => 8000, 'channels' => 1],
    ['name' => 'GSM', 'pt' => 3, 'rate' => 8000, 'channels' => 1],
    ['name' => 'L16', 'pt' => 96, 'rate' => 48000, 'channels' => 1],
] as $legacyCodec) {
    $legacySdp = SdpHelper::buildLocalSdp('192.0.2.10', 50000, $legacyCodec);
    opusAssert(str_contains($legacySdp, 'telephone-event/8000'), $legacyCodec['name'] . ' DTMF regression');
    opusAssert(str_contains($legacySdp, 'a=ptime:20'), $legacyCodec['name'] . ' ptime regression');
    opusAssert(!str_contains($legacySdp, 'useinbandfec='), $legacyCodec['name'] . ' received Opus fmtp');
}

$remoteMono = OpusConfig::parseFmtp('stereo=0;sprop-stereo=0;useinbandfec=0;maxaveragebitrate=24000');
$stereoToMono = OpusConfig::negotiate($stereo, $remoteMono, 20);
opusSame(1, $stereoToMono['channels'], 'stereo offer / mono answer did not fall back');
opusSame(false, $stereoToMono['fec'], 'FEC intersection');
$remoteStereo = OpusConfig::parseFmtp('stereo=1;sprop-stereo=1;useinbandfec=1;maxaveragebitrate=96000');
opusSame(1, OpusConfig::negotiate($defaults, $remoteStereo, 20)['channels'], 'remote stereo bypassed local mono policy');
opusSame(1, OpusConfig::negotiate($defaults, $remoteMono, 20)['channels'], 'remote mono/local mono result');

// Every frontend bitrate reaches SDP, the native encoder and a real RTP packet;
// FEC limitation remains explicit because opusChannel exposes no FEC setter.
foreach (OpusConfig::ALLOWED_BITRATES as $bitrate) {
    $config = OpusConfig::normalize(['maxAverageBitrate' => $bitrate]);
    $bitrateSdp = SdpHelper::buildLocalSdp('192.0.2.10', 50000, $codec, null, $config);
    opusAssert(str_contains($bitrateSdp, "maxaveragebitrate={$bitrate}"), "SDP bitrate {$bitrate}");
    [$bitrateMedia, $bitrateSocket, $bitrateMemberId] = opusMedia($config);
    $applied = $bitrateMedia->members[$bitrateMemberId]['opusEncoderApplied'];
    opusSame($bitrate, $applied['bitrate'], "encoder bitrate {$bitrate}");
    opusSame(false, $applied['fecApplied'], 'extension unexpectedly reported FEC control');
    $bitrateMedia->sendPcmToLeg('a', pcmSine(960, 48000, 440), 48000, 1);
    opusSame(1, count($bitrateSocket->packets), "RTP bitrate {$bitrate}");
    opusAssert(decodeOpusRtp($bitrateSocket->packets[0])['payload'] !== '', "empty RTP bitrate {$bitrate}");
    $bitrateMedia->close();
    opusSame([], $bitrateMedia->members, "encoder/member cleanup bitrate {$bitrate}");
}

// Real Opus RTP from 10 ms browser frames: packet count and timestamp gap follow ptime.
$ptimeTimings = [];
foreach (OpusConfig::ALLOWED_PACKET_TIMES as $ptime) {
    $config = OpusConfig::normalize(['ptime' => $ptime, 'maxAverageBitrate' => 32000]);
    [$media, $socket, $id] = opusMedia($config);
    $media->members[$id]['rtpChannel']->timestamp = 1000;
    $pcm10 = pcmSine(480, 48000, 440);
    $start = hrtime(true);
    $chunks = intdiv($ptime * 2, 10);
    for ($i = 0; $i < $chunks; $i++) $media->sendPcmToLeg('a', $pcm10, 48000, 1);
    $elapsedMs = (hrtime(true) - $start) / 1e6;
    opusSame(2, count($socket->packets), "ptime {$ptime}: RTP packet count");
    $first = decodeOpusRtp($socket->packets[0]);
    $second = decodeOpusRtp($socket->packets[1]);
    opusSame(111, $first['pt'], "ptime {$ptime}: Opus PT");
    opusSame(48 * $ptime, $second['timestamp'] - $first['timestamp'], "ptime {$ptime}: RTP timestamp increment");
    opusSame(48 * $ptime, $media->members[$id]['samplesPerPacket'], "ptime {$ptime}: samplesPerPacket");
    $decoder = new opusChannel(48000, 1);
    opusAssert(strlen($decoder->decode($first['payload'])) === 48 * $ptime * 2, "ptime {$ptime}: decoded PCM length");
    $decoder->destroy();
    $ptimeTimings[$ptime] = round($elapsedMs, 3);
}

// Real pacing: 10 ms browser frames are released on monotonic deadlines; the
// libspech accumulator emits RTP only at each negotiated packet boundary.
$packetGaps = [];
Swoole\Coroutine\run(function () use (&$packetGaps): void {
    foreach (OpusConfig::ALLOWED_PACKET_TIMES as $ptime) {
        $config = OpusConfig::normalize(['ptime' => $ptime]);
        [$media, $socket] = opusMedia($config);
        $pcm10 = pcmSine(480, 48000, 440);
        $chunks = intdiv($ptime * 3, 10);
        for ($chunk = 0; $chunk < $chunks; $chunk++) {
            $media->sendPcmToLeg('a', $pcm10, 48000, 1);
            Coroutine::sleep(0.010);
        }
        opusSame(3, count($socket->packets), "ptime {$ptime}: paced packet count");
        $gaps = [];
        for ($i = 1; $i < count($socket->sentAtMs); $i++) $gaps[] = $socket->sentAtMs[$i] - $socket->sentAtMs[$i - 1];
        $average = array_sum($gaps) / count($gaps);
        opusAssert($average >= $ptime - 3 && $average <= $ptime + 15,
            "ptime {$ptime}: measured RTP gap {$average}ms");
        $packetGaps[$ptime] = round($average, 2);
    }
});

// Real stereo: independent 440 Hz left and 880 Hz right survive encode/decode.
$stereo60 = OpusConfig::normalize([...$stereo, 'ptime' => 60]);
[$stereoMedia, $stereoSocket, $stereoId] = opusMedia($stereo60);
$stereoPcm = stereoSines(2880, 48000, 440, 880);
$stereoMedia->sendPcmToLeg('a', $stereoPcm, 48000, 2);
opusSame(1, count($stereoSocket->packets), 'stereo packet not emitted');
$stereoPayload = decodeOpusRtp($stereoSocket->packets[0])['payload'];
$stereoDecoder = new opusChannel(48000, 2);
$decodedStereo = $stereoDecoder->decode($stereoPayload);
$leftHz = zeroCrossFrequency($decodedStereo, 0, 2, 48000);
$rightHz = zeroCrossFrequency($decodedStereo, 1, 2, 48000);
opusAssert($decodedStereo !== \libspech\Sip\monoToStereo(\libspech\Sip\stereoToMono($decodedStereo)), 'stereo collapsed to dual mono');
opusAssert($leftHz > 350 && $leftHz < 550, 'left 440 Hz did not survive Opus: ' . $leftHz);
opusAssert($rightHz > 700 && $rightHz < 1050, 'right 880 Hz did not survive Opus: ' . $rightHz);
$stereoDecoder->destroy();

// SDP maxplaybackrate/maxcapturerate must not force an intermediate PCM format.
// A 48 kHz browser stream remains 48 kHz all the way into MediaChannel.
$stereo24kConfig = OpusConfig::normalize([...$stereo, 'ptime' => 60, 'maxCaptureRate' => 24000, 'maxPlaybackRate' => 24000]);
[$stereo24kMedia, $stereo24kSocket] = opusMedia($stereo24kConfig);
$stereo24kCapture = stereoSines(2880, 48000, 440, 880);
$stereo24kMedia->sendPcmToLeg('a', $stereo24kCapture, 48000, 2);
opusSame(1, count($stereo24kSocket->packets), '24k stereo capture did not reach RTP');
$stereo24kDecoder = new opusChannel(48000, 2);
$stereo48kDecoded = $stereo24kDecoder->decode(decodeOpusRtp($stereo24kSocket->packets[0])['payload']);
opusSame(2880 * 2 * 2, strlen($stereo48kDecoded), 'fmtp forced an intermediate PCM playback size');
$left24kHz = zeroCrossFrequency($stereo48kDecoded, 0, 2, 48000);
$right24kHz = zeroCrossFrequency($stereo48kDecoded, 1, 2, 48000);
opusAssert($left24kHz > 350 && $left24kHz < 550, 'fmtp playback collapsed left channel: ' . $left24kHz);
opusAssert($right24kHz > 700 && $right24kHz < 1050, 'fmtp playback collapsed right channel: ' . $right24kHz);
$stereo24kDecoder->destroy();
$stereo24kMedia->close();

// Mono member remains truly one-channel; hardware fallback config also maps to one channel.
[$monoMedia, , $monoId] = opusMedia($defaults);
opusSame(1, $monoMedia->members[$monoId]['channels'], 'mono member became dual mono');
$fallback = OpusConfig::normalize([...$stereo, 'channels' => 1, 'stereo' => false]);
opusSame(1, $fallback['channels'], 'hardware stereo->mono fallback');
opusAssert(str_contains(OpusConfig::buildFmtp($fallback), 'stereo=0;sprop-stereo=0'), 'fallback SDP is still stereo');

// DTMF 1, 2 and # use the negotiated PT/clock for Opus (48 kHz local offer
// and 8 kHz remote offer), including duration in the negotiated time base.
$dtmfResults = [];
Swoole\Coroutine\run(function () use (&$dtmfResults): void {
    foreach ([[48000, 101, 7680], [8000, 110, 1280]] as [$clock, $pt, $duration]) {
        [$media, $socket] = opusMedia(OpusConfig::defaults(), $clock, 111, $pt);
        foreach ([['1', 1], ['2', 2], ['#', 11]] as [$digit, $event]) {
            $before = count($socket->packets);
            $media->send2833($digit);
            opusAssert(count($socket->packets) >= $before + 4, "DTMF {$clock}/{$digit}: packet sequence missing");
            $last = decodeOpusRtp($socket->packets[array_key_last($socket->packets)]);
            $payload = unpack('Cevent/Cflags/nduration', $last['payload']);
            opusSame($pt, $last['pt'], "DTMF {$clock}/{$digit}: PT");
            opusSame($event, $payload['event'], "DTMF {$clock}/{$digit}: event");
            opusSame($duration, $payload['duration'], "DTMF {$clock}/{$digit}: duration");
            $dtmfResults[$clock][$digit] = ['pt' => $last['pt'], 'duration' => $payload['duration']];
        }
        $media->close();
        opusSame([], $media->members, "DTMF {$clock}: cleanup");
    }
});

// Repeatable CPU/PPS/payload comparison over one second of generated PCM.
$cpuComparison = [];
foreach ([1, 2] as $benchmarkChannels) {
    foreach (OpusConfig::ALLOWED_PACKET_TIMES as $ptime) {
        $benchmarkConfig = OpusConfig::normalize([
            'channels' => $benchmarkChannels,
            'stereo' => $benchmarkChannels === 2,
            'maxPlaybackRate' => 48000,
            'maxCaptureRate' => 48000,
            'maxAverageBitrate' => $benchmarkChannels === 2 ? 96000 : 32000,
            'ptime' => $ptime,
        ]);
        [$benchmarkMedia, $benchmarkSocket] = opusMedia($benchmarkConfig);
        $chunk = $benchmarkChannels === 2
            ? stereoSines(480, 48000, 440, 880)
            : pcmSine(480, 48000, 440);
        $started = hrtime(true);
        for ($i = 0; $i < 100; $i++) $benchmarkMedia->sendPcmToLeg('a', $chunk, 48000, $benchmarkChannels);
        $cpuMs = (hrtime(true) - $started) / 1e6;
        $payloadBytes = array_sum(array_map(static fn(string $rtp): int => max(0, strlen($rtp) - 12), $benchmarkSocket->packets));
        $expectedPackets = intdiv(1000, $ptime);
        opusSame($expectedPackets, count($benchmarkSocket->packets), "benchmark {$benchmarkChannels}ch/{$ptime}: PPS");
        $cpuComparison["{$benchmarkChannels}ch_{$ptime}ms"] = [
            'encode_cpu_ms_per_audio_second' => round($cpuMs, 3),
            'pps' => count($benchmarkSocket->packets),
            'payload_kbps' => round($payloadBytes * 8 / 1000, 2),
        ];
    }
}

/** @param list<int> $wireBytes */
function simulateBadNetwork(array $wireBytes, int $ptime, int $bandwidthBps): array
{
    $departAt = 0.0;
    $arrivals = [];
    $lost = 0;
    $jitterPattern = [-12, 4, 18, -5, 9, -2, 14, 0];
    foreach ($wireBytes as $index => $bytes) {
        $sentAt = $index * $ptime;
        if (($index + 1) % 20 === 0) { // deterministic 5% RTP loss
            $lost++;
            continue;
        }
        $serializationMs = ($bytes * 8 * 1000) / $bandwidthBps;
        $departAt = max($departAt, $sentAt) + $serializationMs;
        $arrivals[] = $departAt + 60 + $jitterPattern[$index % count($jitterPattern)];
    }
    $interArrivalJitter = [];
    for ($i = 1; $i < count($arrivals); $i++) {
        $interArrivalJitter[] = abs(($arrivals[$i] - $arrivals[$i - 1]) - $ptime);
    }
    sort($interArrivalJitter);
    $p95 = $interArrivalJitter === [] ? 0 : $interArrivalJitter[(int)floor((count($interArrivalJitter) - 1) * 0.95)];
    $durationSeconds = max(0.001, count($wireBytes) * $ptime / 1000);
    return [
        'sent' => count($wireBytes), 'received' => count($arrivals), 'lost' => $lost,
        'loss_percent' => round(100 * $lost / max(1, count($wireBytes)), 2),
        'jitter_p95_ms' => round($p95, 2),
        'wire_kbps' => round(array_sum($wireBytes) * 8 / 1000 / $durationSeconds, 2),
        'last_arrival_ms' => round($arrivals[array_key_last($arrivals)] ?? 0, 2),
    ];
}

// Poor-network comparison uses actual encoded RTP sizes plus deterministic
// delay/jitter/5% loss and a 128 kbps bottleneck. FEC recovery is not claimed,
// because the installed opusChannel has no encoder FEC control.
$networkProfiles = [];
$profileInputs = [
    'mono24_fec_ptime20' => ['channels' => 1, 'bitrate' => 24000, 'ptime' => 20, 'fec' => true],
    'mono32_fec_ptime20' => ['channels' => 1, 'bitrate' => 32000, 'ptime' => 20, 'fec' => true],
    'mono24_fec_ptime40' => ['channels' => 1, 'bitrate' => 24000, 'ptime' => 40, 'fec' => true],
    'stereo96_ptime20' => ['channels' => 2, 'bitrate' => 96000, 'ptime' => 20, 'fec' => false],
];
foreach ($profileInputs as $name => $profile) {
    $networkConfig = OpusConfig::normalize([
        'channels' => $profile['channels'], 'stereo' => $profile['channels'] === 2,
        'maxPlaybackRate' => $profile['channels'] === 2 ? 48000 : 24000,
        'maxCaptureRate' => $profile['channels'] === 2 ? 48000 : 24000,
        'maxAverageBitrate' => $profile['bitrate'], 'ptime' => $profile['ptime'], 'fec' => $profile['fec'],
    ]);
    [$networkMedia, $networkSocket] = opusMedia($networkConfig);
    $networkChunk = $profile['channels'] === 2
        ? stereoSines(480, 48000, 440, 880)
        : pcmSine(480, 48000, 440);
    for ($chunk = 0; $chunk < 200; $chunk++) {
        $networkMedia->sendPcmToLeg('a', $networkChunk, 48000, $profile['channels']);
    }
    $wireBytes = array_map('strlen', $networkSocket->packets);
    $networkProfiles[$name] = simulateBadNetwork($wireBytes, $profile['ptime'], 128000);
    $networkProfiles[$name]['fec_negotiated'] = $profile['fec'];
    $networkProfiles[$name]['fec_encoder_applied'] = false;
}
opusAssert($networkProfiles['stereo96_ptime20']['wire_kbps'] > $networkProfiles['mono32_fec_ptime20']['wire_kbps'], 'stereo network cost not visible');
opusAssert($networkProfiles['mono24_fec_ptime40']['sent'] < $networkProfiles['mono24_fec_ptime20']['sent'], 'ptime40 did not reduce PPS');

echo "OK: Opus config, fmtp, mono/stereo, offer/answer, dynamic PT, bitrate, FEC status, DTMF and RTP ptime.\n";
echo 'RTP_PTIME encode_ms=' . json_encode($ptimeTimings) . ' timestamp_gaps={"10":480,"20":960,"40":1920,"60":2880}' . "\n";
echo 'RTP_GAP measured_ms=' . json_encode($packetGaps) . "\n";
echo 'STEREO decoded_left_hz=' . round($leftHz, 1) . ' decoded_right_hz=' . round($rightHz, 1) . "\n";
echo 'DTMF ' . json_encode($dtmfResults) . "\n";
echo 'CPU_PPS ' . json_encode($cpuComparison) . "\n";
echo 'BAD_NETWORK_SIMULATION ' . json_encode($networkProfiles) . "\n";
