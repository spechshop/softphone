<?php

use Swoole\Timer;

class cache
{


    public static function arrayShift(string $key): mixed
    {
        $element = self::get($key);
        $shifted = array_shift($element);
        self::set($key, $element);
        return $shifted;
    }

    public static function get(string $nameKey): mixed
    {
        if ($nameKey === 'traces') return [];
        return $GLOBALS[$nameKey] ?? null;
    }

    public static function set(string $string, mixed $queue): void
    {
        if ($string !== 'traces')
            $GLOBALS[$string] = $queue;
        else
            $GLOBALS[$string] = [];
    }

    public static function join(string $key, $value): bool
    {
        $element = self::get($key);
        if (is_array($element)) {
            $element[] = $value;
            self::set($key, $element);
            return true;
        } else {
            return false;
        }
    }

    public static function subJoin(string $key, string $subKey, mixed $value): bool
    {
        $GLOBALS[$key][$subKey][] = $value;
        return true;
    }

    public static function subDefine(string $key, string $subKey, mixed $value): void
    {
        $GLOBALS[$key][$subKey] = $value;
    }

    public static function findConnection($username): ?array
    {
        $connections = self::getConnections();
        return $connections[$username] ?? null;
    }


    public static function global(): ?array
    {
        return $GLOBALS;
    }

    public static function deleteConnection($username): void
    {
        $table = cache::global()['tableConnections'];
        $table->del($username);
    }

    public static function updateConnections(mixed $connections): void
    {
        $table = cache::global()['tableConnections'];
        if (!is_array($connections)) return;
        foreach ($connections as $key => $connection) {
            $table->set($key, [
                'address' => $connection['address'],
                'port' => $connection['port'],
                'userAgent' => $connection['userAgent'],
                'timestamp' => $connection['timestamp'],
                'expires' => $connection['expires'],
            ]);
        }
    }

    public static function persistExpungeCall(string $callId, $warning = false): void
    {
        if ($warning) {
            cli::pcl("persistExpungeCall -> " . $warning, 'yellow');
        }
        $dialogProxy = cache::global()['dialogProxy'];

        cache::define('dialogProxy', $dialogProxy);
        Timer::after(rand(100, 1500), function () use ($callId) {

            $dialogProxy = cache::global()['dialogProxy'];
            unset($dialogProxy[$callId]);
            cli::pcl('UNSET LINE 105 ' . $callId);
            cache::define('dialogProxy', $dialogProxy);
            $calls = file_get_contents('calls.json');
            if (str_contains($calls, $callId)) {
                $dialogProxy = cache::global()['dialogProxy'];
                unset($dialogProxy[$callId]);
                cache::define('dialogProxy', $dialogProxy);
                Timer::after(rand(100, 1500), function () use ($callId) {
                    $dialogProxy = cache::global()['dialogProxy'];
                    unset($dialogProxy[$callId]);
                    cache::define('dialogProxy', $dialogProxy);

                });
            }
        });
    }

    public static function define(string $key, mixed $value): bool
    {
        $GLOBALS[$key] = $value;
        return true;
    }

    public static function increment(string $key): void
    {
        $GLOBALS[$key]++;
    }

    public static function decrement(string $key): void
    {
        $GLOBALS[$key]--;
    }


    public static function unset(string $key, string $subKey): void
    {
        try {
            if (isset($GLOBALS[$key][$subKey])) unset($GLOBALS[$key][$subKey]);
        } catch (\Throwable $th) {
            try {
                unset($GLOBALS[$key]->$subKey);
            } catch (\Throwable $th) {
                cli::pcl("Erro ao tentar deletar a chave $key.$subKey");
            }
        }

    }

    public static function sum(string $keyIntCounter, mixed $value): void
    {
        $GLOBALS[$keyIntCounter] += $value;
    }

}