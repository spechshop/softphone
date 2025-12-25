<?php

use Swoole\Coroutine\Channel;

class Async
{
    private Channel $promise;

    public function __construct(callable $callback)
    {
        $this->promise = new Swoole\Coroutine\Channel(1);
        go(function () use ($callback) {
            $result = $callback();
            $this->promise->push($result);
        });
    }

    public function then(callable $callback): void
    {
        go(function () use ($callback) {
            $result = $this->promise->pop();
            $callback($result);
        });
    }

    public function await(): mixed
    {
        return $this->promise->pop();
    }
}

function async(callable $callback): Async
{
    return new Async($callback);
}

function await(Async $async)
{
    return $async->await();
}

