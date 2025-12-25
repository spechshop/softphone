<?php

namespace plugins\Database;

use plugins\Request\appController;

class call
{
    public static function data(): ?array
    {
        $dir = appController::baseDir() . '/database/';
        $addressTokens = $dir . 'tokens.lotus';
        if (!file_exists($addressTokens)) file_put_contents($addressTokens, json_encode([]));
        return json_decode(file_get_contents($addressTokens), true);
    }
}