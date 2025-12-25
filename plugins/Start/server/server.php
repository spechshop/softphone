<?php

namespace plugins\Start;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveTreeIterator;
use Swoole\Table;
use Swoole\Timer;

class server
{
    public static function start(\Swoole\Http\Server $server): void
    {
        $cli = new \plugins\Start\consoleManage();
        $tableServer = new \plugins\Start\tableServer();
        $prefix = 'http://';
        if ($server->port === 443) {
            $prefix = 'https://';
        }
        if (!empty($server->ssl)) {
            $prefix = 'https://';
        }
        print $cli->color(sprintf("O servidor está sendo executado no endereço => %s%s:%s%s", $prefix, $server->host, $server->port, PHP_EOL), 'yellow') . PHP_EOL;
        self::tick($server, 3000, $tableServer);

        \cache::set('cachePages', []);

        $listRoutes = \plugins\Request\controller::listPages();
        foreach ($listRoutes as $listRoute) {
            $e = explode('/', $listRoute);
            $idKey = explode('.', $e[count($e) - 1])[0];

            \cache::subDefine('cachePages', $idKey, \bufferPages::get($idKey, __DIR__));
        }


    }

    public static function tick(\Swoole\Http\Server $server, int $milliseconds, Table $tableServer)
    {
        Timer::tick($milliseconds, function ($timerId) use ($server, $tableServer) {
            $algorithm = 'crc32';
            $Iterator = new RecursiveTreeIterator(new RecursiveDirectoryIterator(".", FilesystemIterator::SKIP_DOTS));
            foreach ($Iterator as $path) {
                $addressFile = explode('-./', $path)[1];
                $eTypeOf = explode('.', $addressFile);
                $typeOf = $eTypeOf[count($eTypeOf) - 1];
                if (in_array($typeOf, $GLOBALS['allowObservable']) and strpos($path, 'files/') === false) {
                    if (str_contains($addressFile, 'files')) {
                        continue;
                    }
                    if (str_contains($addressFile, 'vendor')) {
                        continue;
                    }
                    if (str_contains($addressFile, 'node_modules')) {
                        continue;
                    }
                    if (str_contains($addressFile, 'stubs')) {
                        continue;
                    }
                    if (is_file($addressFile)) {
                        $id = md5($addressFile);
                        if (get_debug_type($tableServer) !== 'plugins\Start\tableServer') {
                            return Timer::clear($timerId);
                        }
                        if (empty($tableServer->get($id, 'identifier'))) {
                            $tableServer->set($id, [
                                'identifier' => $id,
                                'data' => hash_file($algorithm, $addressFile),
                            ]);
                        }
                        $nowHash = hash_file($algorithm, $addressFile);
                        if ($nowHash !== $tableServer->get($id, 'data')) {
                            cache::define('breakAllLoops', true);
                            $tableServer->del($id);
                            $tableServer->destroy();
                            unset($tableServer);
                            $fdsToClose = [];
                            foreach ($server->connections as $fd => $connection) {
                                $fdsToClose[] = $fd;
                            }
                            foreach ($fdsToClose as $fd) {
                                $server->close($fd);
                            }
                            $server->reload();
                            $server->shutdown();
                            $server->stop();
                            $tableServer = null;
                            Timer::clearAll();
                        }
                    }
                }
            }
        });
    }
}