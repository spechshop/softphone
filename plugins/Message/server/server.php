<?php

namespace plugins;

use plugins\websocket\OpenConnection;
use Swoole\WebSocket\Frame;


class server extends OpenConnection
{
    public static function message(\Swoole\WebSocket\Server $server, Frame $frame): bool
    {


        $resolveMessage = new handlerMessage($server, $frame);
        return $resolveMessage->resolve();


    }
}
