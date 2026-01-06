<?php

declare(strict_types=1);

namespace swoole\coroutine {


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


    function batch(array $tasks, float $timeout = -1.0): \array
    {
        return [];
    }


    function parallel(int $n, callable $fn): \void
    {
        return;
    }

    /**
     * Applies the callback to the elements of the given list.
     *
     * The callback function takes on two parameters. The list parameter's value being the first, and the key/index second.
     * Each callback runs in a new coroutine, allowing the list to be processed in parallel.
     *
     * @param array $list A list of key/value paired input data.
     * @param callable $fn The callback function to apply to each item on the list. The callback takes on two parameters.
     *                     The list parameter's value being the first, and the key/index second.
     * @param float $timeout > 0 means waiting for the specified number of seconds. other means no waiting.
     * @return array Returns an array containing the results of applying the callback function to the corresponding value
     *               and key of the list (used as arguments for the callback). The returned array will preserve the keys of
     *               the list.
     */
    function map(array $list, callable $fn, float $timeout = -1.0): \array
    {
        return [];
    }


    function deadlock_check()
    {
        return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
    }

}
