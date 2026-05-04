<?php


\Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);


use libspech\Cache\cache as cacheLibSpech;
use libspech\Network\network;
use plugins\Start\cache;
use Swoole\WebSocket\Server;


global $server;
global $coroutinesProcess;


print "Thread started..." . PHP_EOL;
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


cache::define('breakAllLoops', false);


\libspech\Cache\cache::set('connections', []);
\libspech\Cache\cache::set('frameIds', []);
$serverSettings = cache::global()['interface']['serverSettings'];
$GLOBALS['coroutinesProcess'] = [];
if (cache::global()['interface']['ssl']) $server = new Server(cache::global()['interface']['host'], cache::global()['interface']['port'], SWOOLE_BASE, SWOOLE_SOCK_TCP | SWOOLE_SSL);
else $server = new Server(cache::global()['interface']['host'], 8080, SWOOLE_BASE, SWOOLE_SOCK_TCP);
$server->listen(cache::global()['interface']['host'], 4000, SWOOLE_SOCK_UDP);


cache::define('server', $server);
cache::define('routes', []);
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
    if (empty($parse['method'])) {
        return;
    }
    if ($info['address'] === '127.0.0.1' && count($parse['headers']['Via']) > 1) {
        $localIp = network::getLocalIp();
        $localPort = 4000;
        foreach ($parse['headers']['Via'] as $via) {
            $parseVia = \libspech\Sip\sip::extractVia($via);
            if ($parseVia['address'] === $localIp && $parseVia['port'] === $localPort) continue;
            $socket->sendto($parseVia['address'], $parseVia['port'], $data);
            cli::pcl("Sending packet $parse[methodForParser] to {$parseVia['address']}:{$parseVia['port']}");
        }
        return; // Retorna para não processar o pacote injetado localmente como se fosse recebido
    }
    cli::pcl($data, 'cyan');

    if ($parse['method'] === 'OPTIONS') {
        $respondOk = \libspech\Packet\renderMessages::respondOptions($parse['headers']);
        $socket->sendto($info['address'], $info['port'], $respondOk);
    }

    if ($parse['method'] === 'MESSAGE') {
        $respondOk = \libspech\Packet\renderMessages::respond200OK($parse['headers'], '');
        $socket->sendto($info['address'], $info['port'], $respondOk);

        $fromUser = \libspech\Sip\sip::extractURI($parse['headers']['From'][0])['user'] ?? '';
        $toUser = \libspech\Sip\sip::extractURI($parse['headers']['To'][0])['user'] ?? '';
        $body = trim($parse['body'] ?? '');

        if (!empty($fromUser) && !empty($toUser) && !empty($body)) {
            $msg = \plugins\Utils\messages\messageStore::saveMessage($fromUser, $toUser, $body);
            if ($msg) {
                \plugins\Utils\messages\messageStore::sendRealtime($socket, $toUser, [
                    'type' => 'messageNew',
                    'data' => ['message' => $msg]
                ]);
            }
        }
    }
});





$server->start();

