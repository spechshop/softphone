<?php

namespace plugins\Request;

use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;

class appController
{
    /**
     * @param mixed $pid
     * @param mixed $sig_num
     * @return bool
     */
    public static function pKill(mixed $pid, mixed $sig_num = 9): bool
    {
        $idProcess = (int)$pid;
        if (function_exists("posix_kill")) return posix_kill($idProcess, $sig_num);
        exec("/usr/bin/kill -s $sig_num $idProcess 2>&1", $junk, $return_code);
        return !$return_code;
    }

    /**
     * @throws ReflectionException
     */
    public static function mountFunction($name): ?string
    {
        $args = appController::getFunctionArgs($name);
        $implode = implode(', ', $args);
        return "{$name}($implode)";
    }

    /**
     * @param $funcName
     * @return array|null
     * @throws ReflectionException
     */
    public static function getFunctionArgs($funcName): ?array
    {
        $f = new ReflectionFunction($funcName);
        $result = [];
        foreach ($f->getParameters() as $param) {
            $result[] = $param->name;
        }
        return $result;
    }

    /**
     * @param $className
     * @param $methodName
     * @return array|null
     * @throws ReflectionException
     */
    public static function getMethodArgs($className, $methodName): ?array
    {
        $m = new ReflectionMethod($className, $methodName);
        $result = [];
        foreach ($m->getParameters() as $param) {
            $result[] = $param->name;
        }
        return $result;
    }

    /**
     * @param $request
     * @param $response
     * @param $app
     * @return bool|null
     */
    public static function call($request, $response, $app): ?bool
    {
        $callable = "\\plugins\\Request\\$app::api";
        if (is_callable($callable))
            return (bool)self::mountCallable($callable, $request, $response, $app);
        else return false;
    }

    /**
     * @param $callable
     * @param $request
     * @param $response
     * @param $app
     * @return callable|null <p>checks if the returned error</p>
     */
    public static function mountCallable($callable, $request, $response, $app)
    {
        return call_user_func_array($callable, [$request, $response, $app]);
    }

    /**
     * @return string|null
     */
    public static function baseDir($master = false): ?string
    {
        if ($master) {
            return explode('manage/plugins', __DIR__)[0];

        }
        return explode('plugins', __DIR__)[0];
    }

    public static function loadPath(string $dir): ?array
    {
        $files = scandir($dir);
        $result = [];
        foreach ($files as $file) {
            if ($file == '.' or $file == '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $result = array_merge($result, self::loadPath($path));
            } else {
                $result[] = $path;
            }
        }
        return $result;
    }

    public static function listFilesAndDirs($dir): array
    {
        if (!is_dir($dir)) return [];
        $items = scandir($dir);
        $filesAndDirs = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $fullPath = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_file($fullPath) || (is_dir($fullPath) && !is_link($fullPath))) $filesAndDirs[] = $fullPath;
        }

        return $filesAndDirs;
    }

}
