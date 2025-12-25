<?php

namespace handlers;

use bufferPages;
use plugins\Extension\StringUtils;
use plugins\Request\appController;

class getPage
{
    public static function resolve(\Swoole\WebSocket\Server $socket, array $model, int $fd): ?bool
    {
        $data = $model['data'];
        $namePage = $data['page'];
        if (str_starts_with($namePage, '/')) $namePage = substr($namePage, 1);


        var_dump("!".$namePage);


        $socket->push($fd, json_encode([
            'byToken' => $model['id'],
            'data' => bufferPages::get($namePage, appController::baseDir() . 'plugins')
        ]));




        return false;
    }
}