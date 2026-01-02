<?php


\Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);


use libspech\Cache\cache as cacheLibSpech;
use plugins\Start\cache;
use Swoole\WebSocket\Server;


global $server;
global $coroutinesProcess;

include 'libspech/plugins/autoloader.php';
include 'plugins/autoload.php';


$serverSettings = cacheLibSpech::get('interface');
$interfacetr = cacheLibSpech::get('interface');

if (cacheLibSpech::get('interface')['ssl']) {
    if (array_key_exists('ssl_cert_file', $serverSettings['serverSettings'])) {
        if (!file_exists(cacheLibSpech::get('interface')['serverSettings']['ssl_cert_file'])) {
            $keyFile = $interfacetr['serverSettings']['ssl_key_file'];
            $certFile = $interfacetr['serverSettings']['ssl_cert_file'];
            \libspech\Cli\cli::pcl("Generating SSL certificates...");
            \libspech\Cli\cli::pcl("Arquivos: $keyFile, $certFile");

            // Gerar chave privada e certificado em arquivos separados
            shell_exec('openssl req -x509 -nodes -days 365 -newkey rsa:2048 -keyout ' . escapeshellarg($keyFile) . ' -out ' . escapeshellarg($certFile) . ' -subj "/C=BR/ST=State/L=City/O=Organization/OU=Unit/CN=localhost" 2>&1');
            sleep(4);
            // Aguardar a criação dos arquivos
            $maxWait = 10;
            $waited = 0;
            while ($waited < $maxWait) {
                if (file_exists($certFile) && file_exists($keyFile)) {
                    break;
                }
                sleep(1);
                $waited++;
            }


            if (!file_exists($certFile) || !file_exists($keyFile)) {
                throw new Error("Falha ao gerar certificados SSL. Verifique se o OpenSSL está instalado.");
            } else {
                $serverSettings = cacheLibSpech::get('interface')['serverSettings'];
                $serverSettings['ssl_cert_file'] = $certFile;
                $serverSettings['ssl_key_file'] = $keyFile;
            }
        }


    } else {
        throw new Error("INVALID SSL CONFIGURATION: ssl_cert_file and ssl_key_file must be set in interface.json");
    }
}





print "Thread started..." . PHP_EOL;
cache::define('breakAllLoops', false);


\libspech\Cache\cache::set('connections', []);
\libspech\Cache\cache::set('frameIds', []);
$serverSettings = cache::global()['interface']['serverSettings'];
$GLOBALS['coroutinesProcess'] = [];
if (cache::global()['interface']['ssl']) $server = new Server(cache::global()['interface']['host'], cache::global()['interface']['port'], SWOOLE_BASE, SWOOLE_SOCK_TCP | SWOOLE_SSL);
else $server = new Server(cache::global()['interface']['host'], 8080, SWOOLE_BASE, SWOOLE_SOCK_TCP);
$server->listen(cache::global()['interface']['host'], 4000, SWOOLE_SOCK_UDP);


cache::define('server', $server);
$server->set($serverSettings);
$server->on('open', '\plugins\server::open');
$server->on('message', '\plugins\server::message');
$server->on('Start', '\plugins\Start\server::start');
$server->on('Request', '\plugins\Request\server::request');
$server->on('close', function ($server, $fd) {
    cache::searchAndRemove('allowedFds', $fd);
    $connections = \libspech\Cache\cache::get('connections');
    foreach ($connections as $fp => $fds) {
        foreach ($fds as $l => $id) {
            if ($id === $fd) {
                unset($connections[$fp][$l]);
            }
        }
    }
    cache::set('connections', $connections);
});

$server->on('packet', function (Server $socket, string $data, array $info) {
    $parse = \libspech\Sip\sip::parse($data);
    if ($parse['method'] === 'OPTIONS') {
        $respondOk = \libspech\Packet\renderMessages::respondOptions($parse['headers']);
        $socket->sendto($info['address'], $info['port'], $respondOk);
    }
    \libspech\Cli\cli::pcl($data);
});





$server->start();

