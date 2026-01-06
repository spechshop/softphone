<?php

declare(strict_types=1);


class bcg729Channel
{


    public function __construct()
    {
        return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
    }


    public function decode(\string $input): \string
    {
        return "";
    }


    public function encode(\string $input): \string
    {
        return "";
    }


    public function info()
    {
        return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
    }


    public function close()
    {
        return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
    }
}
