<?php

namespace plugins;

use AllowDynamicProperties;
use cli;


#[AllowDynamicProperties] class handlerMessage
{
    public function __construct(\Swoole\WebSocket\Server $server, \Swoole\WebSocket\Frame $frame)
    {
        $data = $frame->data;
        $this->solved = [];
        $this->socket = $server;
        $tryDecode = json_decode($data, true);
        if (!is_array($tryDecode)) $this->solved['type'] = 'invalid';
        $this->solved = array_merge($this->solved, $tryDecode);
        $this->info = $frame->fd;
    }

    public function resolve()
    {
        $method = strtolower($this->method());
        if (method_exists('handlers\\' . $method, 'resolve')) {
            return call_user_func('handlers\\' . $method . '::resolve', $this->socket, $this->solved, $this->info);
        }
        cli::pcl("Method {$method} not found");
        return false;
    }

    public function method()
    {
        return $this->solved['type'] ?? 'invalid';
    }

}
