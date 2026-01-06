<?php

declare(strict_types=1);

namespace co {


    function run(callable $fn, $args = NULL)
    {
        return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
    }


    function go(callable $fn, $args = NULL)
    {
        return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
    }


    function defer(callable $fn)
    {
        return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
    }

}
