<?php

declare(strict_types=1);


class Resampler
{


    public function __construct(\int $srcRate = NULL, \int $dstRate = NULL)
    {
        return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
    }


    public function reset()
    {
        return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
    }


    public function sample(\string $pcm, \int $srcRate = NULL, \int $dstRate = NULL): \string
    {
        return "";
    }


    public function process(\string $pcm): \string
    {
        return "";
    }


    public function returnEmpty()
    {
        return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
    }
}
