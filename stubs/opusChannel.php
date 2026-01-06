<?php

declare(strict_types=1);


class opusChannel
{


    public function __construct(\int $sample_rate = NULL, \int $channels = NULL)
    {
        return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
    }


    public function encode(\string $pcm_data, \int $pcm_rate = NULL): \string
    {
        return "";
    }


    public function decode(\string $encoded_data, \int $pcm_rate_out = NULL): \string
    {
        return "";
    }


    public function resample(\string $pcm_data, \int $src_rate, \int $dst_rate): \string
    {
        return "";
    }


    public function setBitrate(\int $value)
    {
        return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
    }


    public function setVBR(\bool $enable)
    {
        return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
    }


    public function setComplexity(\int $value)
    {
        return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
    }


    public function setDTX(\bool $enable)
    {
        return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
    }


    public function setSignalVoice(\bool $enable)
    {
        return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
    }


    public function reset()
    {
        return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
    }


    public function enhanceVoiceClarity(\string $pcm_data, \float $intensity = NULL): \string
    {
        return "";
    }


    public function spatialStereoEnhance(\string $pcm_data, \float $width = NULL, \float $depth = NULL): \string
    {
        return "";
    }


    public function monoToStereo(\string $pcm_data): \string
    {
        return "";
    }


    public function stereoToMono(\string $pcm_data): \string
    {
        return "";
    }


    public function hasLibsoxr(): \mixed
    {
        return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
    }


    public function getInfo(): \array
    {
        return [];
    }


    public function destroy()
    {
        return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
    }
}
