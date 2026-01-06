<?php

declare(strict_types=1);


function swoole_exec(string $command, &$output = NULL, &$returnVar = NULL)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_shell_exec(string $cmd)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_curl_init(string $url = ''): \Swoole\Curl\Handler
{
    return class_exists(\Swoole\Curl\Handler::class) ? \Swoole\Curl\Handler::class : \stdClass::class;
}


function swoole_curl_setopt(Swoole\Curl\Handler $obj, int $opt, $value): \bool
{
    return false;
}


function swoole_curl_setopt_array(Swoole\Curl\Handler $obj, $array): \bool
{
    return false;
}


function swoole_curl_exec(Swoole\Curl\Handler $obj)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_curl_getinfo(Swoole\Curl\Handler $obj, int $opt = 0)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_curl_errno(Swoole\Curl\Handler $obj): \int
{
    return 0;
}


function swoole_curl_error(Swoole\Curl\Handler $obj): \string
{
    return "";
}


function swoole_curl_reset(Swoole\Curl\Handler $obj)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_curl_close(Swoole\Curl\Handler $obj): \void
{
    return;
}


function swoole_curl_multi_getcontent(Swoole\Curl\Handler $obj)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_socket_create(int $domain, int $type, int $protocol)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_socket_connect(Swoole\Coroutine\Socket $socket, string $address, int $port = 0)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_socket_read(Swoole\Coroutine\Socket $socket, int $length, int $type = 2)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_socket_write(Swoole\Coroutine\Socket $socket, string $buffer, int $length = 0)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_socket_send(Swoole\Coroutine\Socket $socket, string $buffer, int $length, int $flags)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_socket_recv(Swoole\Coroutine\Socket $socket, &$buffer, int $length, int $flags)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_socket_sendto(Swoole\Coroutine\Socket $socket, string $buffer, int $length, int $flags, string $addr, int $port = 0)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_socket_recvfrom(Swoole\Coroutine\Socket $socket, &$buffer, int $length, int $flags, &$name, &$port = NULL)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_socket_bind(Swoole\Coroutine\Socket $socket, string $address, int $port = 0): \bool
{
    return false;
}


function swoole_socket_listen(Swoole\Coroutine\Socket $socket, int $backlog = 0): \bool
{
    return false;
}


function swoole_socket_create_listen(int $port, int $backlog = 128)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_socket_accept(Swoole\Coroutine\Socket $socket)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_socket_getpeername(Swoole\Coroutine\Socket $socket, &$address, &$port = NULL)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_socket_getsockname(Swoole\Coroutine\Socket $socket, &$address, &$port = NULL)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_socket_set_option(Swoole\Coroutine\Socket $socket, int $level, int $optname, $optval): \bool
{
    return false;
}


function swoole_socket_setopt(Swoole\Coroutine\Socket $socket, int $level, int $optname, $optval): \bool
{
    return false;
}


function swoole_socket_get_option(Swoole\Coroutine\Socket $socket, int $level, int $optname)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_socket_getopt(Swoole\Coroutine\Socket $socket, int $level, int $optname)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_socket_shutdown(Swoole\Coroutine\Socket $socket, int $how = 2): \bool
{
    return false;
}


function swoole_socket_close(Swoole\Coroutine\Socket $socket)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_socket_clear_error(Swoole\Coroutine\Socket $socket = NULL)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_socket_last_error(Swoole\Coroutine\Socket $socket = NULL): \int
{
    return 0;
}


function swoole_socket_set_block(Swoole\Coroutine\Socket $socket)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_socket_set_nonblock(Swoole\Coroutine\Socket $socket)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_socket_create_pair(int $domain, int $type, int $protocol, array &$pair)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}

/**
 * @since 5.0.0
 */
function swoole_socket_import_stream($stream)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_gethostbynamel(string $domain)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_mail(string $to, string $subject, string $message, array $headers = []): \bool
{
    return false;
}


function swoole_checkdnsrr(string $hostname, string $type = 'MX'): \bool
{
    return false;
}


function swoole_dns_check_record(string $hostname, string $type = 'MX'): \bool
{
    return false;
}


function swoole_real_getmxrr(string $hostname, array $hosts = NULL, array $weights = NULL): \array
{
    return [];
}


function swoole_getmxrr(string $hostname, array &$hosts, array &$weights = NULL): \bool
{
    return false;
}


function swoole_dns_get_mx(string $hostname, array &$hosts, array &$weights = NULL): \bool
{
    return false;
}


function swoole_real_dns_get_record(string $hostname, int $type, array $authoritative_name_servers = NULL, array $additional_records = NULL, bool $raw = false): \array
{
    return [];
}


function swoole_dns_get_record(string $hostname, int $type = 268435456, array &$authoritative_name_servers = NULL, array &$additional_records = NULL, bool $raw = false)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_gethostbyaddr(string $ip): \string
{
    return "";
}

/**
 * @param array<string, mixed> $options
 */
function swoole_library_set_options(array $options): \void
{
    return;
}


function swoole_library_get_options(): \array
{
    return [];
}


function swoole_library_set_option(string $key, $value): \void
{
    return;
}


function swoole_library_get_option(string $key)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_string(string $string = ''): \Swoole\StringObject
{
    return class_exists(\Swoole\StringObject::class) ? \Swoole\StringObject::class : \stdClass::class;
}


function swoole_mbstring(string $string = ''): \Swoole\MultibyteStringObject
{
    return class_exists(\Swoole\MultibyteStringObject::class) ? \Swoole\MultibyteStringObject::class : \stdClass::class;
}


function swoole_array(array $array = []): \Swoole\ArrayObject
{
    return class_exists(\Swoole\ArrayObject::class) ? \Swoole\ArrayObject::class : \stdClass::class;
}


function swoole_table(int $size, string $fields): \Swoole\Table
{
    return class_exists(\Swoole\Table::class) ? \Swoole\Table::class : \stdClass::class;
}


function swoole_array_list($arrray = NULL): \Swoole\ArrayObject
{
    return class_exists(\Swoole\ArrayObject::class) ? \Swoole\ArrayObject::class : \stdClass::class;
}


function swoole_array_default_value(array $array, $key, $default_value = NULL)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function swoole_is_in_container(): \bool
{
    return false;
}


function swoole_container_cpu_num(): \int
{
    return 0;
}


function swoole_init_default_remote_object_server(): \void
{
    return;
}


function swoole_get_default_remote_object_client(): \Swoole\RemoteObject\Client
{
    return class_exists(\Swoole\RemoteObject\Client::class) ? \Swoole\RemoteObject\Client::class : \stdClass::class;
}


function _string(string $string = ''): \Swoole\StringObject
{
    return class_exists(\Swoole\StringObject::class) ? \Swoole\StringObject::class : \stdClass::class;
}


function _mbstring(string $string = ''): \Swoole\MultibyteStringObject
{
    return class_exists(\Swoole\MultibyteStringObject::class) ? \Swoole\MultibyteStringObject::class : \stdClass::class;
}


function _array(array $array = []): \Swoole\ArrayObject
{
    return class_exists(\Swoole\ArrayObject::class) ? \Swoole\ArrayObject::class : \stdClass::class;
}


function safeexport($v)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function writestubfile($namespace, $className, $code)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function generatefunctionstubs(string $ext)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function generateextensionconstants(string $ext)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function generateclassstubs(array $allowFilters)
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}


function liststubfolders($dir = '/home/lotus/PROJETOS/spechphone/stubs')
{
    return class_exists(\mixed::class) ? \mixed::class : \stdClass::class;
}

