<?php

namespace plugins\Request;
class loadRouter
{
    public static function view($path, $response): ?array
    {
        $checkRoute = checkRoute::check($path, $response);
        if ($checkRoute['break']) return ['break' => true];
        return ['break' => false];
    }
}