<?php

use plugins\Request\template;

class bufferPages
{
    public static function get($namePage, $dir): ?string
    {
        $addressPage = "plugins/Request/pages/$namePage.html";

        if (!file_exists($addressPage)) {
            return 'what?';
        }
        var_dump($addressPage);
        return template::prepare(file_get_contents($addressPage));
    }
}