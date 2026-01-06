<?php

declare(strict_types=1);

namespace swoole\coroutine\http {

    /**
     * @throws Exception
     */
    function request(string $url, string $method, $data = NULL, array $options = NULL, array $headers = NULL, array $cookies = NULL): \Swoole\Coroutine\Http\ClientProxy
    {
        return class_exists(\Swoole\Coroutine\Http\ClientProxy::class) ? \Swoole\Coroutine\Http\ClientProxy::class : \stdClass::class;
    }

    /**
     * @throws Exception
     */
    function request_with_http_client(string $url, string $method, $data = NULL, array $options = NULL, array $headers = NULL, array $cookies = NULL): \Swoole\Coroutine\Http\ClientProxy
    {
        return class_exists(\Swoole\Coroutine\Http\ClientProxy::class) ? \Swoole\Coroutine\Http\ClientProxy::class : \stdClass::class;
    }

    /**
     * @throws Exception
     */
    function request_with_curl(string $url, string $method, $data = NULL, array $options = NULL, array $headers = NULL, array $cookies = NULL): \Swoole\Coroutine\Http\ClientProxy
    {
        return class_exists(\Swoole\Coroutine\Http\ClientProxy::class) ? \Swoole\Coroutine\Http\ClientProxy::class : \stdClass::class;
    }

    /**
     * @throws Exception
     */
    function request_with_stream(string $url, string $method, $data = NULL, array $options = NULL, array $headers = NULL, array $cookies = NULL): \Swoole\Coroutine\Http\ClientProxy
    {
        return class_exists(\Swoole\Coroutine\Http\ClientProxy::class) ? \Swoole\Coroutine\Http\ClientProxy::class : \stdClass::class;
    }

    /**
     * @throws Exception
     */
    function post(string $url, $data, array $options = NULL, array $headers = NULL, array $cookies = NULL): \Swoole\Coroutine\Http\ClientProxy
    {
        return class_exists(\Swoole\Coroutine\Http\ClientProxy::class) ? \Swoole\Coroutine\Http\ClientProxy::class : \stdClass::class;
    }

    /**
     * @throws Exception
     */
    function get(string $url, array $options = NULL, array $headers = NULL, array $cookies = NULL): \Swoole\Coroutine\Http\ClientProxy
    {
        return class_exists(\Swoole\Coroutine\Http\ClientProxy::class) ? \Swoole\Coroutine\Http\ClientProxy::class : \stdClass::class;
    }

}
