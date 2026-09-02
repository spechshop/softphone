<?php


\Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
}

use helpers\utils\CallState;
use helpers\utils\AccountIdentity;
use helpers\utils\SipRegisterManager;
use helpers\utils\SipMessageBody;
use helpers\utils\PhoneController;
use libspech\Cache\cache as cacheLibSpech;
use libspech\Network\network;
use libspech\Packet\renderMessages;
use libspech\Sip\sip;
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
if (cache::global()['interface']['ssl']) $server = new serverDynamical(cache::global()['interface']['host'], cache::global()['interface']['port'], SWOOLE_BASE, SWOOLE_SOCK_TCP | SWOOLE_SSL);
else $server = new serverDynamical(cache::global()['interface']['host'], 8080, SWOOLE_BASE, SWOOLE_SOCK_TCP);
$server->listen(cache::global()['interface']['host'], 4000, SWOOLE_SOCK_UDP);


CallState::init();
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

function inboundTxKey(array $parse): string
{
    $callId = $parse['headers']['Call-ID'][0] ?? '';
    $cseqNum = explode(' ', $parse['headers']['CSeq'][0] ?? '')[0] ?? '';
    $fromTag = '';
    if (preg_match('/;tag=([^;>\s\r\n]+)/i', $parse['headers']['From'][0] ?? '', $m)) $fromTag = $m[1];
    $toUser = \libspech\Sip\sip::extractURI($parse['headers']['To'][0] ?? '')['user'] ?? '';
    return md5("{$callId}:{$cseqNum}:{$fromTag}:{$toUser}");
}

function inboundCSeqMethod(array $parse): string
{
    $parts = explode(' ', $parse['headers']['CSeq'][0] ?? '');
    return strtoupper($parts[1] ?? '');
}

function inboundRequestUser(string $packet): string
{
    if (!preg_match('/^[A-Z]+\s+([^\s]+)\s+SIP\/2\.0/i', $packet, $match)) return '';
    return (string)(\libspech\Sip\sip::extractURI($match[1])['user'] ?? '');
}

function resolveInboundAccount(string $user, string $domain, string $sourceHost, string $requestUser): array
{
    $route = AccountIdentity::resolve($user, $domain, $sourceHost, null, $requestUser);
    if ($route['accountId']) return $route;

    $registeredFp = CallState::findRegisteredFpForInbound($user, $domain, $sourceHost);
    if (!$registeredFp || ($route['candidates'] && !in_array($registeredFp, $route['candidates'], true))) return $route;
    $account = AccountIdentity::get($registeredFp);
    if (!$account || strcasecmp((string)$account['sipUser'], $user) !== 0) return $route;

    return [
        'status' => 'resolved_binding', 'accountId' => $registeredFp,
        'account' => $account, 'candidates' => [$registeredFp],
    ];
}

$server->on('packet', function (Server $socket, string $data, array $info) {
    $parse = \libspech\Sip\sip::parse($data);
    if (empty($parse['method'])) {
        return;
    }
    cli::pcl($data);

    // REGISTER uses this listener as both its source and its only response
    // reader. Consume a response before the generic SIP dispatcher can route,
    // log or otherwise race with the pending client transaction.
    if (SipRegisterManager::handleResponse($parse, $info)) {
        $code = (int)$parse['method'];
        $color = $code >= 400 ? 'red' : ($code >= 200 ? 'green' : 'yellow');
        cli::pcl(
            '[REGISTER] resposta ' . $code
            . ' Call-ID:' . ($parse['headers']['Call-ID'][0] ?? 'N/A')
            . ' CSeq:' . ($parse['headers']['CSeq'][0] ?? 'N/A'),
            $color
        );
        return;
    }

    // Outbound transactions/dialogs share the same listener. Once consumed,
    // a packet must never fall through to another handler.
    if (PhoneController::instance($socket)->handlePacket($parse, $info)) {
        return;
    }

    // Via routing only for SIP responses (numeric method). Requests (INVITE/BYE/etc) are processed normally.
    if (is_numeric($parse['method']) && count($parse['headers']['Via']) > 1) {
        $localIp = network::getLocalIp();
        $localPort = 4000;
        $respCode = (int)$parse['method'];
        $respColor = $respCode >= 400 ? 'red' : ($respCode >= 200 ? 'green' : 'yellow');
        cli::pcl("131 [SIP-RESP] {$parse['method']} de {$info['address']}:{$info['port']} Call-ID:" . ($parse['headers']['Call-ID'][0] ?? 'N/A') . " CSeq:" . ($parse['headers']['CSeq'][0] ?? 'N/A'), $respColor);
        foreach ($parse['headers']['Via'] as $via) {
            $parseVia = \libspech\Sip\sip::extractVia($via);
            if ($parseVia['address'] === $localIp && (int)$parseVia['port'] === $localPort) continue;
            $socket->sendto($parseVia['address'], $parseVia['port'], $data);
            cli::pcl("[SIP-RESP] Roteando {$parse['method']} → {$parseVia['address']}:{$parseVia['port']}");
        }
        return;
    }
    // Log de respostas com Via único (destinadas a nós mesmos)
    if (is_numeric($parse['method'])) {
        $respCode = (int)$parse['method'];
        $respColor = $respCode >= 400 ? 'red' : ($respCode >= 200 ? 'green' : 'yellow');
        cli::pcl("144 [SIP-RESP] {$parse['method']} de {$info['address']}:{$info['port']} Call-ID:" . ($parse['headers']['Call-ID'][0] ?? 'N/A') . " CSeq:" . ($parse['headers']['CSeq'][0] ?? 'N/A'), $respColor);
    }
    if ($parse['method'] === 'INVITE') {
        $socket->sendto($info['address'], $info['port'], renderMessages::respond100Trying($parse['headers']));

        $callId = $parse['headers']['Call-ID'][0];
        $txKey = inboundTxKey($parse);

        $toUserDbg = sip::extractURI($parse['headers']['To'][0] ?? '')['user'] ?? 'N/A';
        $fromUserDbg = sip::extractURI($parse['headers']['From'][0] ?? '')['user'] ?? 'N/A';
        $viaDbg = $parse['headers']['Via'][0] ?? 'N/A';
        cli::pcl("[INBOUND] INVITE de {$info['address']}:{$info['port']} From:{$fromUserDbg} To:{$toUserDbg} Call-ID:{$callId}", 'cyan');
        cli::pcl("[INBOUND] Via: {$viaDbg}", 'cyan');

        // Deduplication: retransmit last response for the same transaction
        if (CallState::$incomingCalls->exist($callId)) {
            $existing = CallState::$incomingCalls->get($callId);

            // Same tx_key = pure retransmission, just resend last response
            // Different tx_key but same callId = re-INVITE or duplicate from proxy: update SDP and resend
            $newSdpParsed = \helpers\utils\SdpHelper::parseRemoteSdp($parse['sdp'] ?? []);
            CallState::$incomingCalls->set($callId, array_merge($existing, [
                'invite_headers_json' => json_encode($parse['headers']),
                'invite_sdp_json' => json_encode($parse['sdp'] ?? []),
                'remote_ip' => $info['address'],
                'remote_port' => $info['port'],
                'remote_rtp_ip' => $newSdpParsed['ip'],
                'remote_rtp_port' => $newSdpParsed['port'],
                'tx_key' => $txKey,
                'updated_at' => time(),
            ]));
            $existing = CallState::$incomingCalls->get($callId);

            $hdrs = json_decode($existing['invite_headers_json'], true);
            $hdrs['To'][0] = ($hdrs['To'][0] ?? '') . ';tag=' . $existing['to_tag'];
            if ($existing['status'] === 'ringing') {
                $socket->sendto($info['address'], $info['port'], renderMessages::baseResponse($hdrs, "180", "Ringing", [
                    "Contact" => ["<sip:s@" . network::getLocalIp() . ":4000>"],
                    "Allow" => ["INVITE, ACK, BYE, CANCEL, OPTIONS, MESSAGE, INFO, REGISTER"],
                ]));
                cli::pcl("[INBOUND] INVITE duplicado — SDP atualizado, reenviando 180 Ringing Call-ID:{$callId}", 'yellow');
            } elseif (in_array($existing['status'], ['accepted', 'active'], true)) {
                $socket->sendto($info['address'], $info['port'], renderMessages::baseResponse($hdrs, "200", "OK", [
                    "Contact" => ["<sip:s@" . network::getLocalIp() . ":4000>"],
                    "Allow" => ["INVITE, ACK, BYE, CANCEL, OPTIONS, MESSAGE, INFO, REGISTER"],
                ]));
                cli::pcl("[INBOUND] INVITE duplicado — SDP atualizado, chamada em status '{$existing['status']}', reenviando 200 OK Call-ID:{$callId}", 'yellow');
            }
            return;
        }

        $sdpParsed = \helpers\utils\SdpHelper::parseRemoteSdp($parse['sdp'] ?? []);
        $chosenCodec = \helpers\utils\SdpHelper::chooseCodec($sdpParsed['codecs']);
        cli::pcl("[INBOUND] SDP remoto IP:{$sdpParsed['ip']}:{$sdpParsed['port']} codecs:" . implode(',', array_column($sdpParsed['codecs'], 'name')), 'cyan');
        if (!$chosenCodec) {
            $socket->sendto($info['address'], $info['port'], renderMessages::baseResponse($parse['headers'], "488", "Not Acceptable Here"));
            cli::pcl("[INBOUND] Codec incompatível, 488 enviado Call-ID:{$callId}", 'red');
            return;
        }
        cli::pcl("[INBOUND] Codec selecionado: {$chosenCodec['name']}/{$chosenCodec['rate']} pt:{$chosenCodec['pt']}", 'cyan');

        $toUri = sip::extractURI($parse['headers']['To'][0] ?? '');
        $toUser = $toUri['user'] ?? '';
        $toDomain = (string)($toUri['peer']['host'] ?? '');
        $requestUser = inboundRequestUser($data);
        $route = resolveInboundAccount($toUser, $toDomain, (string)($info['address'] ?? ''), $requestUser);
        $fp = $route['accountId'];

        if (!$fp) {
            $socket->sendto($info['address'], $info['port'], renderMessages::baseResponse($parse['headers'], "480", "Temporarily Unavailable"));
            $reason = $route['status'] === 'ambiguous' ? 'ambiguous' : 'not found';
            cli::pcl("[CALL:ROUTE] {$reason} destination user={$toUser} domain={$toDomain} requestUser={$requestUser} candidates=" . count($route['candidates']), 'red');
            return;
        }
        cli::pcl("[CALL:ROUTE] to={$toUser} domain={$toDomain} requestUser={$requestUser} accountId={$fp} resolution={$route['status']}", 'cyan');

        if (CallState::hasActiveCallForFp($fp, $callId) || isset((cache::get('coroutinesProcess') ?? [])[$fp])) {
            $socket->sendto($info['address'], $info['port'], renderMessages::baseResponse($parse['headers'], "486", "Busy Here"));
            if (CallState::$incomingCalls->exist($callId)
                && CallState::$incomingCalls->get($callId)['status'] === 'pending_user') {
                CallState::$incomingCalls->del($callId);
            }
            cli::pcl("[INBOUND] Usuário {$toUser} (fp:{$fp}) ocupado, 486 enviado", 'red');
            return;
        }

        if (empty(cache::get('connections')[$fp] ?? [])) {
            $pendingToTag = bin2hex(random_bytes(4));
            $pendingFromTag = '';
            if (preg_match('/;tag=([^;>\s\r\n]+)/i', $parse['headers']['From'][0] ?? '', $m)) $pendingFromTag = $m[1];
            $pendingInviteCseq = explode(' ', $parse['headers']['CSeq'][0] ?? '')[0] ?? '';

            CallState::$incomingCalls->set($callId, [
                'call_id' => $callId,
                'fp' => $fp,
                'status' => 'pending_user',
                'from_uri' => $parse['headers']['From'][0] ?? '',
                'to_uri' => $parse['headers']['To'][0] ?? '',
                'remote_ip' => $info['address'],
                'remote_port' => $info['port'],
                'remote_rtp_ip' => $sdpParsed['ip'],
                'remote_rtp_port' => $sdpParsed['port'],
                'local_rtp_port' => 0,
                'codec' => $chosenCodec['name'],
                'frequency' => $chosenCodec['rate'],
                'owner_worker_id' => 0,
                'invite_headers_json' => json_encode($parse['headers']),
                'invite_sdp_json' => json_encode($parse['sdp'] ?? []),
                'tx_key' => $txKey,
                'to_tag' => $pendingToTag,
                'from_tag' => $pendingFromTag,
                'invite_cseq' => $pendingInviteCseq,
                'last_response_code' => 100,
                'created_at' => time(),
                'updated_at' => time(),
            ]);

            $maxWait = 30;
            cli::pcl("[INBOUND] fp:{$fp} sem WS ativo — enviando push e aguardando reconexão (até {$maxWait}s)", 'yellow');

            $fromHeader = $parse['headers']['From'][0] ?? '';
            go(fn() => \helpers\utils\WebPushHelper::notifyIncomingCall($fp, [
                'fromUri' => AccountIdentity::sipUri($fromHeader),
                'callId' => $callId,
            ]));


            $cameOnline = false;
            $waited = 0;
            for ($n = $maxWait; $n--;) {
                \Swoole\Coroutine::sleep(1);
                $waited += 1;
                if (!CallState::$incomingCalls->exist($callId)) {
                    cli::pcl("[INBOUND] Call-ID:{$callId} removido durante espera (CANCEL recebido), abortando", 'yellow');
                    return;
                }
                if (!empty(cache::get('connections')[$fp] ?? [])) {
                    $cameOnline = true;
                    break;
                }
            }

            if (!$cameOnline) {
                $finalHdrs = $parse['headers'];
                $finalHdrs['To'][0] = ($finalHdrs['To'][0] ?? '') . ';tag=' . $pendingToTag;
                $socket->sendto($info['address'], $info['port'], renderMessages::baseResponse($finalHdrs, "480", "Temporarily Unavailable"));
                CallState::$incomingCalls->del($callId);
                cli::pcl("[INBOUND] fp:{$fp} não reconectou em {$maxWait}s, 480 enviado", 'red');
                return;
            }

            cli::pcl("[INBOUND] fp:{$fp} reconectou após {$waited}s — continuando fluxo", 'green');
        }

        if (CallState::hasActiveCallForFp($fp, $callId) || isset((cache::get('coroutinesProcess') ?? [])[$fp])) {
            $socket->sendto($info['address'], $info['port'], renderMessages::baseResponse($parse['headers'], "486", "Busy Here"));
            if (CallState::$incomingCalls->exist($callId)
                && CallState::$incomingCalls->get($callId)['status'] === 'pending_user') {
                CallState::$incomingCalls->del($callId);
            }
            cli::pcl("[INBOUND] Usuário {$toUser} (fp:{$fp}) ocupado, 486 enviado", 'red');
            return;
        }

        // Generate stable To tag and extract From tag / CSeq
        $toTag = bin2hex(random_bytes(4));
        $fromTag = '';
        if (preg_match('/;tag=([^;>\s\r\n]+)/i', $parse['headers']['From'][0] ?? '', $m)) $fromTag = $m[1];
        $inviteCseq = explode(' ', $parse['headers']['CSeq'][0] ?? '')[0] ?? '';

        CallState::$incomingCalls->set($callId, [
            'call_id' => $callId,
            'fp' => $fp,
            'status' => 'ringing',
            'from_uri' => $parse['headers']['From'][0] ?? '',
            'to_uri' => $parse['headers']['To'][0] ?? '',
            'remote_ip' => $info['address'],
            'remote_port' => $info['port'],
            'remote_rtp_ip' => $sdpParsed['ip'],
            'remote_rtp_port' => $sdpParsed['port'],
            'local_rtp_port' => 0,
            'codec' => $chosenCodec['name'],
            'frequency' => $chosenCodec['rate'],
            'owner_worker_id' => 0,
            'invite_headers_json' => json_encode($parse['headers']),
            'invite_sdp_json' => json_encode($parse['sdp'] ?? []),
            'tx_key' => $txKey,
            'to_tag' => $toTag,
            'from_tag' => $fromTag,
            'invite_cseq' => $inviteCseq,
            'last_response_code' => 180,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        // 180 Ringing with stable To tag
        $hdrsWithTag = $parse['headers'];
        $hdrsWithTag['To'][0] .= ';tag=' . $toTag;
        $socket->sendto($info['address'], $info['port'], renderMessages::baseResponse($hdrsWithTag, "180", "Ringing", [
            "Contact" => ["<sip:s@" . network::getLocalIp() . ":4000>"],
            "Allow" => ["INVITE, ACK, BYE, CANCEL, OPTIONS, MESSAGE, INFO, REGISTER"],
        ]));

        foreach (cache::get('connections')[$fp] ?? [] as $clientFd) {
            $socket->push($clientFd, json_encode([
                'type' => 'incomingCall',
                'data' => [
                    'accountId' => $fp,
                    'callId' => $callId,
                    'from' => $parse['headers']['From'][0] ?? '',
                    'to' => $parse['headers']['To'][0] ?? '',
                    'fromUri' => AccountIdentity::sipUri($parse['headers']['From'][0] ?? ''),
                    'toUri' => AccountIdentity::sipUri($parse['headers']['To'][0] ?? ''),
                    'codec' => $chosenCodec['name'],
                ],
            ]));
        }
        cli::pcl("[INBOUND] INVITE Call-ID:{$callId} de {$info['address']}:{$info['port']} → {$toUser} (fp:{$fp})", 'yellow');
        cli::pcl("[INBOUND] 100 Trying + 180 Ringing enviados. incomingCall WS enviado.", 'yellow');
        return;
    }


    if ($parse['method'] === 'CANCEL') {
        $callId = $parse['headers']['Call-ID'][0];
        $socket->sendto($info['address'], $info['port'], renderMessages::respond200OK($parse['headers']));
        if (CallState::$incomingCalls->exist($callId)) {
            $row = CallState::$incomingCalls->get($callId);
            $inviteHeaders = json_decode($row['invite_headers_json'], true);
            $inviteHeaders['To'][0] = ($inviteHeaders['To'][0] ?? '') . ';tag=' . $row['to_tag'];
            $socket->sendto($info['address'], $info['port'], renderMessages::respond487RequestTerminated($inviteHeaders));
            $fp = $row['fp'];
            CallState::$incomingCalls->del($callId);
            foreach (cache::get('connections')[$fp] ?? [] as $clientFd) {
                $socket->push($clientFd, json_encode(['type' => 'event', 'data' => 'bye']));
                $socket->push($clientFd, json_encode(['type' => 'notify', 'data' => ['type' => 'bg-warning text-white', 'message' => 'Chamada cancelada']]));
            }
            cli::pcl("[INBOUND] CANCEL Call-ID:{$callId} — chamada cancelada", 'yellow');
        }
        return;
    }

    if ($parse['method'] === 'BYE' && CallState::$incomingCalls->exist($parse['headers']['Call-ID'][0])) {
        $callId = $parse['headers']['Call-ID'][0];
        $socket->sendto($info['address'], $info['port'], renderMessages::respond200OK($parse['headers']));
        $row = CallState::$incomingCalls->get($callId);
        $fp = $row['fp'];
        $procs = cache::get('coroutinesProcess') ?? [];
        if (isset($procs[$fp])) {
            $procs[$fp]->receiveBye = true;
            $procs[$fp]->callActive = false;
            cache::unset('coroutinesProcess', $fp);
        }
        CallState::$incomingCalls->del($callId);
        foreach (cache::get('connections')[$fp] ?? [] as $clientFd) {
            $socket->push($clientFd, json_encode(['type' => 'event', 'data' => 'bye']));
            $socket->push($clientFd, json_encode(['type' => 'notify', 'data' => ['type' => 'bg-info text-white', 'message' => 'Chamada encerrada pelo tronco']]));
        }
        cli::pcl("[INBOUND] BYE Call-ID:{$callId} — chamada encerrada", 'yellow');
        return;
    }

    if ($parse['method'] === 'OPTIONS') {
        $respondOk = \libspech\Packet\renderMessages::respondOptions($parse['headers']);
        $socket->sendto($info['address'], $info['port'], $respondOk);
    }

    if ($parse['method'] === 'MESSAGE') {
        $respondOk = \libspech\Packet\renderMessages::respond200OK($parse['headers'], '');
        $socket->sendto($info['address'], $info['port'], $respondOk);

        $fromParsed = \libspech\Sip\sip::extractURI($parse['headers']['From'][0] ?? '');
        $toParsed = \libspech\Sip\sip::extractURI($parse['headers']['To'][0] ?? '');
        $fromUser = $fromParsed['user'] ?? '';
        $fromDomain = (string)($fromParsed['peer']['host'] ?? '');
        $toUser = $toParsed['user'] ?? '';
        $toDomain = (string)($toParsed['peer']['host'] ?? '');
        $fromUri = AccountIdentity::sipUri($parse['headers']['From'][0] ?? '');
        $toUri = AccountIdentity::sipUri($parse['headers']['To'][0] ?? '');
        $body = trim(SipMessageBody::extract($data, $parse));
        $requestUser = inboundRequestUser($data);
        cli::pcl("[MESSAGE:RX] requestUser={$requestUser} from={$fromUri} to={$toUri} bytes=" . strlen($body), 'cyan');

        if (!empty($fromUser) && !empty($toUser) && !empty($body)) {
            $route = resolveInboundAccount($toUser, $toDomain, (string)($info['address'] ?? ''), $requestUser);
            $accountId = $route['accountId'];
            if (!$accountId) {
                if ($route['status'] === 'ambiguous') {
                    cli::pcl("[MESSAGE:ROUTE] ambiguous destination user={$toUser} requestUser={$requestUser} candidates=" . count($route['candidates']), 'red');
                } else {
                    cli::pcl("[MESSAGE:ROUTE] destination not found user={$toUser} domain={$toDomain}", 'red');
                }
                return;
            }
            cli::pcl("[MESSAGE:ROUTE] to={$toUser} domain={$toDomain} requestUser={$requestUser} accountId={$accountId} resolution={$route['status']}", 'cyan');
            cli::pcl("[MESSAGE:SIP] fromUser={$fromUser} fromDomain={$fromDomain} fromUri={$fromUri} toUser={$toUser} toDomain={$toDomain} toUri={$toUri}", 'cyan');
            $msg = \plugins\Utils\messages\messageStore::saveMessage($accountId, $toUri, $fromUri, $body, 'inbound');
            if ($msg) {
                \plugins\Utils\messages\messageStore::sendRealtime($socket, $accountId, [
                    'type' => 'messageNew',
                    'data' => ['message' => $msg]
                ]);
                go(fn() => \helpers\utils\WebPushHelper::notifyUser($accountId, $msg));
            }
        } else {
            cli::pcl("[MESSAGE:DROP] invalid SIP MESSAGE fromUser={$fromUser} toUser={$toUser} bytes=" . strlen($body), 'red');
        }
    }
});


$server->start();

class serverDynamical extends \Swoole\WebSocket\Server
{
    public function __construct($host, $port, $mode, $sock_type)
    {
        parent::__construct($host, $port, $mode, $sock_type);
    }
}
