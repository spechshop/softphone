<?php

namespace plugins\Request;

use plugin\Utils\network;
use Swoole\Http\Request;
use Swoole\Http\Response;

class dns
{
    public static function api(Request $request, Response $response): bool
    {
        $response->setHeader('Content-Type', 'application/json');
        return $response->end(json_encode([
            'success' => true,
            'ip' => self::getLocalIp()
        ]));
    }

    public static function getLocalIp(): ?string
    {
        $localAddress = '0.0.0.0';
        foreach (swoole_get_local_ip() as $localAddress) {
            if (!empty(filter_var($localAddress, FILTER_VALIDATE_IP))) {
                break;
            }
        }
        return $localAddress;
    }

}
