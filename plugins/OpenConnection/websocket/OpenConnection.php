<?php

namespace plugins\websocket;

use plugins\Start\cache;
use Swoole\Http\Request;
use Swoole\WebSocket\Server;


cache::define('allowedFds', []);

class OpenConnection
{
    public static function open(Server $server, Request $request)
    {
        return true;
    }
}
