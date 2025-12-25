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

        try {
            var_dump(cache::get('breakAllLoops'));
        } catch (\Throwable $e) {
            // Handle the exception if needed
            //var_dump($e);
            return false;
        }

        print 'open' . PHP_EOL;
    }
}
