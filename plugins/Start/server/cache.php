<?php

namespace plugins\Start;

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
        return $GLOBALS[$nameKey] ?? null;
    }

    public static function set(string $string, mixed $queue): void
    {
        $GLOBALS[$string] = $queue;
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


    public static function global(): ?array
    {
        return $GLOBALS;
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

    public static function inArray(string $key, mixed $value): bool
    {
        return in_array($value, $GLOBALS[$key] ?? []);
    }
    public static function searchAndRemove(string $key, mixed $value): void
    {
        if (isset($GLOBALS[$key]) && is_array($GLOBALS[$key])) {
            $index = array_search($value, $GLOBALS[$key]);
            if ($index !== false) {
                unset($GLOBALS[$key][$index]);
            }
        }
    }



    public static function unset(string $key, string $subKey): void
    {
        if (isset($GLOBALS[$key][$subKey])) unset($GLOBALS[$key][$subKey]);
    }

    public static function sum(string $keyIntCounter, mixed $value): void
    {
        $GLOBALS[$keyIntCounter] += $value;
    }


}