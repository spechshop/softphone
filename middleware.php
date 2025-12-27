<?php


\Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);


use plugins\Start\cache;
use Swoole\WebSocket\Server;


global $server;
global $coroutinesProcess;

include 'libspech/plugins/autoloader.php';
include 'plugins/autoload.php';


print "Thread started..." . PHP_EOL;
cache::define('breakAllLoops', false);



function getLocalIp(): ?string
{
    $localAddress = '0.0.0.0';
    if (!empty(cache::get('myIpAddress'))) return cache::get('myIpAddress');
    foreach (swoole_get_local_ip() as $localAddress) {
        if (!empty(filter_var($localAddress, FILTER_VALIDATE_IP))) {
            break;
        }
    }
    return $localAddress;
}



$serverSettings = cache::global()['interface']['serverSettings'];
$GLOBALS['coroutinesProcess'] = [];
if (cache::global()['interface']['ssl']) $server = new Server(cache::global()['interface']['host'], cache::global()['interface']['port'], SWOOLE_BASE, SWOOLE_SOCK_TCP | SWOOLE_SSL);
else $server = new Server(cache::global()['interface']['host'], 8080, SWOOLE_BASE, SWOOLE_SOCK_TCP);


$server->listen(cache::global()['interface']['host'], 4043, SWOOLE_SOCK_TCP);
cache::define('server', $server);
$server->set($serverSettings);
$server->on('open', '\plugins\server::open');
$server->on('message', '\plugins\server::message');
$server->on('Start', '\plugins\Start\server::start');
$server->on('Request', '\plugins\Request\server::request');
$server->on('close', function ($server, $fd) {
    cache::searchAndRemove('allowedFds', $fd);
});

$server->start();

