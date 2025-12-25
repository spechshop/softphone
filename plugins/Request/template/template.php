<?php

namespace plugins\Request;

class template
{
    public static function prepare($template)
    {
        if (strpos($template, '@import(') !== false) {
            $extension = '.html';
            $ei = explode('@import(', $template);
            $pathModules = explode('plugins/', __DIR__)[0] . 'plugins/Request/modules/';
            foreach ($ei as $i) {
                $module = explode(')', $i)[0];
                if (file_exists($pathModules . $module . $extension)) $template = str_replace('@import(' . $module . ')', file_get_contents($pathModules . $module . $extension), $template);
            }
        }
        return $template;
    }
}