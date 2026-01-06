<?php

declare(strict_types=1);


class LPCM
{


    public function __construct(\int $channels, \int $bitDepth, \bool $isBigEndian = NULL)
    {
        return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
    }


    public function encodeMono(\array $samples): \string
    {
        return "";
    }


    public function decodeMono(\string $pcmData): \array
    {
        return [];
    }


    public function encodeStereo(\array $leftSamples, \array $rightSamples): \string
    {
        return "";
    }


    public function decodeStereo(\string $pcmData): \array
    {
        return [];
    }
}
