<?php

use libspech\Cli\cli;

include (is_dir('libspech' ? 'libspech/' : '')) . "plugins/autoloader.php";;

\co\run(function () {


    $phone = new \libspech\Sip\trunkController('lotus', '', \libspech\Network\network::getLocalIp(), $port);
    $phone->setCallerId('discadora');
    $phone->mountLineCodecSDP('PCMA/8000');
    $phone->defineAudioFile('extra/assets/music.wav');


    $phone->onAnswer(function (\libspech\Sip\trunkController $phone) {
        $phone->receiveMedia();
        $phone->defineAudioFile('extra/assets/music.wav');
        \Swoole\Coroutine::sleep(3);
        //$phone->stopAudioFile();
        $phone->send2833('9');
        \Swoole\Coroutine::sleep(10);
        $phone->bye();
    });
    $phone->onFailed(function ($message) {
        cli::pcl("Chamada falhou: $message", "bold_red");
    });
    $phone->onHangup(function () {
        cli::pcl("Chamada encerrada", "bold_red");
    });
    $phone->onKeyPress(function ($event, $peer) {
        cli::pcl("DTMF: " . $event, 'bold_green');
    });

    $phone->call('lotus');


});