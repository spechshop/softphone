<?php
declare(strict_types=1);

require __DIR__ . '/../plugins/Utils/helpers/OpusConfig.php';
require __DIR__ . '/../plugins/Utils/helpers/AudioConfig.php';

use helpers\utils\AudioConfig;
use helpers\utils\OpusConfig;

function audioConfigExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$fromOpus = AudioConfig::normalize(null, OpusConfig::presets()['stereo']);
audioConfigExpect($fromOpus['audio']['channels'] === 2 && $fromOpus['audio']['stereo'] === true, 'áudio não herdou estéreo do Opus');
audioConfigExpect($fromOpus['audio']['ptime'] === 20, 'áudio não herdou ptime do Opus');

$mono60 = AudioConfig::normalize([
    'microphoneId' => 'browser-device-123',
    'channels' => 1,
    'ptime' => 60,
    'autoGainControl' => false,
], OpusConfig::presets()['stereo']);
audioConfigExpect($mono60['audio'] === [
    'microphoneId' => 'browser-device-123',
    'channels' => 1,
    'stereo' => false,
    'ptime' => 60,
    'autoGainControl' => false,
], 'preferências do microfone não foram normalizadas');
audioConfigExpect($mono60['opus']['channels'] === 1 && $mono60['opus']['ptime'] === 60, 'microfone e Opus ficaram dessincronizados');

$invalid = AudioConfig::normalize(['stereo' => true, 'ptime' => 30, 'autoGainControl' => '0'], null);
audioConfigExpect($invalid['audio']['channels'] === 2, 'stereo booleano não foi aceito');
audioConfigExpect($invalid['audio']['ptime'] === 20, 'ptime inválido chegou ao backend');
audioConfigExpect($invalid['audio']['autoGainControl'] === false, 'AGC falso não foi preservado');

$longId = AudioConfig::normalize(['microphoneId' => str_repeat('x', 700)], null);
audioConfigExpect(strlen($longId['audio']['microphoneId']) === AudioConfig::MAX_DEVICE_ID_LENGTH, 'deviceId não foi limitado');

foreach ([
    __DIR__ . '/../plugins/Message/handlers/saveAudioConfig.php',
    __DIR__ . '/../plugins/Message/handlers/saveConfig.php',
    __DIR__ . '/../plugins/Message/handlers/connect.php',
] as $backendFile) {
    audioConfigExpect(str_contains((string)file_get_contents($backendFile), 'AudioConfig::normalize'), basename($backendFile) . ' não usa normalização canônica');
}

echo "OK: microfone, mono/estéreo, ptime, AGC e sincronização Opus normalizados no backend.\n";
