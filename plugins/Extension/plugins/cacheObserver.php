<?php

namespace plugins\Utils\cache;

/*
    * This class is responsible for observing the cache of the pages.
    * It is used to check if the cache of a page has been modified.
    * If the cache of a page has been modified, the server is restarted.
*/


class observer
{
    public static function check($namePage, $dir): void
    {
        $addressPage = "$dir/Request/pages/$namePage.html";
        if (!file_exists($addressPage)) return;
        $cache = filemtime($addressPage);
        $cacheOld = filemtime("$dir/Request/cache/$namePage.html");
        if ($cache !== $cacheOld) {
            $message = sprintf("server restarted at %s%s", date('d/m/Y H:i:s'), PHP_EOL);
            $messageGreen = sprintf("The server was restarted due to a modification of a file!%s%s", PHP_EOL, PHP_EOL);
            $cli = new \plugins\Start\consoleManage();
            print $cli->color($message, 'light_blue');
            print $cli->color($messageGreen, 'light_green');
            exit;
        }
    }
}