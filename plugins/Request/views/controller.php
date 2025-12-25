<?php

namespace plugins\Request;

class controller
{
    public static function listPages(): ?array
    {
        $pages = [];
        $filePath = explode('/Request', __DIR__)[0] . '/Request/pages/';
        if ($handle = opendir($filePath)) {
            while (false !== ($entry = readdir($handle))) {
                if ($entry != "." && $entry != "..") {
                    $pages[] = $filePath . $entry;
                }
            }
            closedir($handle);
        }
        return $pages;
    }
}