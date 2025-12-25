<?php

use Swoole\Coroutine\Socket;


class voipUtils
{

    public mixed $username;
    public mixed $password;
    public mixed $host;
    public mixed $port;
    public Socket $socket;
    public int $expires;
    public string $localIp;
    public string $callId;
    public int $timestamp = 0;
    public int $audioReceivePort;
    public string $nonce;
    public bool $isRegistered = false;
    public int $csq;
    public int $ssrc;
    public bool $receiveBye;
    public bool|array $headers200;

    public string $calledNumber;
    public array $dtmfCallbacks = [];

    public float $lastTime = 0;
    public string $sequenceCodes = '';
    public string $callerId;
    public array $dtmfClicks = [];
    public array $asteriskCodes = [];
    public bool $c180;
    public bool $error;
    public $onAnswerCallback;
    public $onRingFailureCallback;
    public bool $enableAudioRecording;
    public string $audioRecordingFile = '';
    public string $recordAudioBuffer = '';
    public array $dtmfList = [];
    public int $sequenceNumber = 0;
    public int $trys = 0;
    public int $registerCount;
    public int $startSilenceTimer = 0;
    public int $silenceTimer = 0;
    public array $silenceVolume = [];
    public $onTimeoutCallback;
    public bool|string $musicOnSilence = false;
    public bool $disableSilence;
    private int $len;
    private int $timeoutCall;
    private string $sendAudioAddressPath;


    public function __construct(mixed $username, mixed $password, mixed $host, mixed $port = 5060)
    {
        $this->username = $username;
        $this->callerId = $username;
        $this->password = $password;
        $this->audioReceivePort = 0;
        $this->host = gethostbyname($host);
        $this->port = $port;
        $this->expires = 60;
        $this->timeoutCall = time();
        $this->localIp = $this->getLocalIp();
        $this->csq = rand(100, 99999);
        $this->receiveBye = false;
        $this->headers200 = false;
        $this->calledNumber = '';
        $this->ssrc = random_int(0, 0xFFFFFFFF);
        $this->callId = bin2hex(random_bytes(16));
        $this->socket = new Socket(AF_INET, SOCK_DGRAM, 0);
        $this->socket->connect($this->host, $this->port);
        $this->c180 = false;
        $this->error = false;
        $this->onAnswerCallback = false;
        $this->onRingFailureCallback = false;
        $this->enableAudioRecording = false;
        $this->recordAudioBuffer = '';
        $this->dtmfList = [];
        $this->registerCount = 0;
        $this->startSilenceTimer = 0;
        $this->silenceTimer = 0;
        $this->silenceVolume = [];
        $this->disableSilence = false;
        $this->sendAudioAddressPath = false;
    }

    public function getLocalIp(): string
    {
        $address = '127.0.0.1';
        $swoole = swoole_get_local_ip();
        foreach ($swoole as $key => $value) {
            if ($value !== $address) {
                $address = $value;
                break;
            }
        }
        return $address;
    }

    public function extractURI($line): array
    {
        if (!str_contains($line, 'sip:')) return [];
        $user = value($line, 'sip:', '@');
        $peerFirst = value($line, $user . '@', '>');
        $peerParts = explode(';', $peerFirst, 2);
        $hostPort = explode(':', $peerParts[0], 2);
        $additionalParams = [];
        if (str_contains($line, '>')) {
            $remaining = substr($line, strpos($line, '>') + 1);
            parse_str(str_replace(';', '&', $remaining), $additionalParams);
        }
        return [
            'user' => $user,
            'peer' => [
                'host' => $hostPort[0],
                'port' => $hostPort[1] ?? '5060',
                'extra' => $peerParts[1] ?? ''
            ],
            'additional' => $additionalParams
        ];
    }

    public function register(): bool|Exception
    {
        if ($this->registerCount > 1) {
            return false;
        }
        $modelRegister = $this->modelRegister();
        $this->socket->sendto($this->host, $this->port, $this->renderSolution($modelRegister));
        $t = 0;
        for (; ;) {
            $rec = $this->socket->recvfrom($peer, 2);
            if (!$rec) {
                $t++;
                if ($t > 3) {
                    $this->registerCount++;
                    return $this->register();
                }
                print $this->color('bold_red', $this->renderSolution($modelRegister)) . PHP_EOL;
                print $this->color('bold_red', "Erro ao registrar telefone em {$this->host}:{$this->port}, tentando novamente...") . PHP_EOL;
                continue;
            } else {
                break;
            }
        }
        $receive = $this->parse($rec);
        if (!array_key_exists('headers', $receive)) {
            print $this->color('bold_red', "Erro ao registrar telefone, tentando novamente...") . PHP_EOL;
            $this->registerCount++;
            return $this->register();
        }
        if (!array_key_exists('WWW-Authenticate', $receive['headers'])) {
            print $this->color('bold_red', "Erro ao registrar telefone, tentando novamente...") . PHP_EOL;
            $this->registerCount++;
            return $this->register();
        }


        $wwwAuthenticate = $receive['headers']['WWW-Authenticate'][0];
        $nonce = value($wwwAuthenticate, 'nonce="', '"');
        $realm = value($wwwAuthenticate, 'realm="', '"');
        $authorization = $this->generateAuthorizationHeader($this->username, $realm, $this->password, $nonce, "sip:{$this->host}", "REGISTER");
        $modelRegister['headers']['Authorization'] = [$authorization];
        $this->socket->sendto($this->host, $this->port, $this->renderSolution($modelRegister));
        for (; ;) {
            if ($this->receiveBye) {
                print "Call ended 1 receiveBye" . PHP_EOL;
                return true;
            }
            $rec = $this->socket->recvfrom($peer, 0.1);
            if (!$rec) {
                continue;
            } else {
                $receive = $this->parse($rec);
            }
            if ($receive['method'] == 'OPTIONS') {
                $respond = $this->respondOptions($receive['headers']);
                $this->socket->sendto($this->host, $this->port, $respond);
            } else {
                if ($receive['headers']['Call-ID'][0] !== $this->callId) {
                    continue;
                } else {
                    break;
                }
            }
        }
        if ($this->receiveBye) {
            print "Call ended 1 receiveBye" . PHP_EOL;
            return true;
        }
        if ($receive['method'] == '200') {
            print $this->color('bold_green', "$receive[method] {$receive['headers']['CSeq'][0]}") . PHP_EOL;
            $this->csq++;
            $this->nonce = $nonce;
            $this->isRegistered = true;
            return true;
        } elseif ($receive['method'] == '403') {
            return false;
        } else {
            print $this->color('bold_red', "Erro ao registrar telefone, tentando novamente...") . PHP_EOL;
            $this->registerCount++;
            return $this->register();
        }
    }

    private function modelRegister(): array
    {
        $localListener = $this->socket->getsockname()['port'];
        return [
            "method" => "REGISTER",
            "methodForParser" => "REGISTER sip:{$this->host} SIP/2.0",
            "headers" => [
                "Via" => ["SIP/2.0/UDP {$this->localIp}:{$localListener};branch=z9hG4bK-" . bin2hex(random_bytes(4))],
                "From" => ["<sip:{$this->username}@{$this->host}>;tag=" . bin2hex(random_bytes(8))],
                "To" => ["<sip:{$this->username}@{$this->host}>"],
                "Call-ID" => [$this->callId],
                "CSeq" => [$this->csq . " REGISTER"],
                "Contact" => ["<sip:{$this->username}@{$this->localIp}:{$localListener}>"],
                "User-Agent" => ["spechSoftPhone"],
                "Expires" => [$this->expires],
                "Content-Length" => ["0"],
            ],
        ];
    }

    public function renderSolution(array $solution): string
    {
        $method = $solution['method'];
        $render = trim($solution['methodForParser']) . "\r\n";
        foreach ($solution['headers'] as $key => $value) {
            if ($key == $method) continue;
            if (str_contains($key, $solution['methodForParser'])) continue;
            if ($key == 'Content-Length') continue;
            if ($key == 'Content-Type') continue;
            if (is_array($value)) foreach ($value as $vx) $render .= "$key: $vx\r\n";
        }
        $sdpRendering = '';
        if (array_key_exists('sdp', $solution)) {
            foreach ($solution['sdp'] as $key => $value) {
                foreach ($value as $v) {
                    $sdpRendering .= "$key=$v\r\n";
                }
            }
            $length = strlen($sdpRendering);
            if ($length > 0) {
                $render .= "Content-Type: application/sdp\r\n";
                $render .= "Content-Length: $length\r\n";
                $render .= "\r\n";
                $render .= $sdpRendering;
            }
        } elseif (array_key_exists('body', $solution)) {
            $render .= "Content-Type: {$solution['headers']['Content-Type'][0]}\r\n";
            $render .= "Content-Length: " . strlen($solution['body']) . "\r\n";
            $render .= "\r\n";
            $render .= $solution['body'];
        }
        if (!str_contains($render, 'Content-Length')) {
            $render .= "Content-Length: 0\r\n\r\n";
        }
        return $render;
    }

    public function color(string $color, string $text): string
    {
        $colors = [
            'black' => '0;30',
            'dark_gray' => '1;30',
            'blue' => '0;34',
            'light_blue' => '1;34',
            'green' => '0;32',
            'light_green' => '1;32',
            'cyan' => '0;36',
            'light_cyan' => '1;36',
            'red' => '0;31',
            'light_red' => '1;31',
            'purple' => '0;35',
            'light_purple' => '1;35',
            'brown' => '0;33',
            'yellow' => '1;33',
            'light_gray' => '0;37',
            'white' => '1;37',
            'bold_red' => '1;31',
            'bold_green' => '1;32',
            'bold_yellow' => '1;33',
            'bold_blue' => '1;34',
            'bold_purple' => '1;35',
            'bold_cyan' => '1;36',
            'bold_white' => '1;37',
        ];
        return "\033[" . $colors[$color] . "m" . $text . "\033[0m";
    }

    public function parse($dataString = false): ?array
    {
        if ($dataString) $data = $dataString;
        else {
            return [
                'method' => 'empty',
                'headers' => [],
            ];
        }
        $f = self::sdpData($data);
        $solution = [];
        if (!empty($f['Content-Type'][0])) {
            if ($f['Content-Type'][0] == 'application/sdp') {
                if (array_key_exists('Content-Length', $f) and $f['Content-Length'][0]) {
                    $sdp = substr($data, -intval($f['Content-Length'][0]));
                    $sdp = explode(PHP_EOL, $sdp);
                    $keys = [];
                    foreach ($sdp as $v) {
                        $k = explode("=", $v, 2);
                        if (strlen($k[0]) < 1) continue;
                        $keys[$k[0]][] = trim($k[1]);
                    }
                    $solution['sdp'] = $keys;
                }
            } elseif ($f['Content-Type'][0] == 'message/sipfrag') {
                if (array_key_exists('Content-Length', $f) and $f['Content-Length'][0]) {
                    $body = substr($data, -intval($f['Content-Length'][0]));
                    $solution['body'] = $body;
                }
            } elseif ($f['Content-Type'][0] == 'text/plain') {
                if (array_key_exists('Content-Length', $f) and $f['Content-Length'][0]) {
                    $body = substr($data, -intval($f['Content-Length'][0]));
                    $solution['body'] = $body;
                }
            }
        }
        $solution['method'] = key($f);
        $solution['methodForParser'] = trim(explode("\r\n", $data)[0]);
        if (str_contains($solution['method'], 'SIP/2.0')) {
            $s = explode(" ", $solution['method']);
            $solution['method'] = $s[(count($s) > 1) ? 1 : 0];
        }
        $lk = null;
        $solution['headers'] = [];
        foreach ($f as $key => $value) {
            if ($lk == 'Content-Length') break;
            $lk = $key;
            $solution['headers'][$key] = $value;
        }
        // pegar primeira  key de headers
        $firstKey = array_key_first($solution['headers']);
        if ($firstKey == $solution['methodForParser']) unset($solution['headers'][$firstKey]);


        return $solution;
    }

    protected function sdpData(string $data): ?array
    {
        $sdp = [];
        foreach (explode(PHP_EOL, $data) as $line) {
            $line = explode(":", $line, 2);
            $key = trim($line[0]);
            $val = @trim($line[1]);
            if (str_contains($key, ' sip')) {
                $key = explode(' sip', $key)[0];
                $val = 'sip:' . $val;
            }
            $sdp[$key][] = $val;
        }
        return $sdp;
    }

    public function generateAuthorizationHeader($username, $realm, $password, $nonce, $uri, $method): string
    {
        $ha1 = md5("{$username}:{$realm}:{$password}");
        $ha2 = md5("{$method}:{$uri}");
        $response = md5("{$ha1}:{$nonce}:{$ha2}");
        return 'Digest username="' . $username . '", '
            . 'realm="' . $realm . '", '
            . 'nonce="' . $nonce . '", '
            . 'uri="' . $uri . '", '
            . 'response="' . $response . '", '
            . 'algorithm=MD5';
    }

    public function respondOptions(array $headers): string
    {
        $contactUri = [
            'user' => 's',
            'peer' => [
                'host' => $this->getLocalIp()
            ]
        ];
        $headers['Contact'][0] = $this->renderURI($contactUri);

        $additionalHeaders = [
            "Contact" => [$headers['Contact'][0]],
            "Allow" => ["INVITE, ACK, BYE, CANCEL, OPTIONS, MESSAGE, INFO, REGISTER"],
            "Supported" => ["replaces, timer"]
        ];
        return $this->baseResponse($headers, "200", "OK", $additionalHeaders);
    }

    public function renderURI(array $uriData): string
    {
        $user = $uriData['user'] ?? '';
        $peer = $uriData['peer'] ?? [];
        $additional = $uriData['additional'] ?? [];
        $host = $peer['host'] ?? '';
        $port = $peer['port'] ?? '';
        $extra = $peer['extra'] ?? '';

        $uri = "<sip:$user@$host";
        if (!empty($port) and $port != '5060') {
            $uri .= ":$port";
        }
        if (!empty($extra)) {
            $uri .= ";$extra";
        }
        $uri .= ">";
        if (!empty($additional)) {
            $additionalParams = [];
            foreach ($additional as $key => $value) {
                $additionalParams[] = "$key=$value";
            }
            $uri .= ";" . implode(';', $additionalParams);
        }
        return $uri;
    }

    public function baseResponse(array $headers, string $statusCode, string $statusMessage, array $additionalHeaders = []): string
    {
        $baseHeaders = [
            "Via" => $headers['Via'],
            "From" => $headers['From'],
            "To" => $headers['To'],
            "Call-ID" => $headers['Call-ID'],
            "CSeq" => $headers['CSeq'],
            "Content-Length" => ["0"],
            "Server" => 'Swoole'
        ];

        $response = [
            "method" => $statusCode,
            "methodForParser" => "SIP/2.0 $statusCode $statusMessage",
            "headers" => array_merge($baseHeaders, $additionalHeaders)
        ];

        return $this->renderSolution($response);
    }

    public function asteriskRegisterCode(string $code, callable $callback): void
    {
        $this->asteriskCodes[$code] = $callback;
    }

    public function generateResponseProxy(string $username, string $password, string $realm, string $nonce, string $uri, string $method, string $qop = "auth", string $nc = "00000001", ?string $cnonce = null): string
    {
        if (!$cnonce) {
            $cnonce = bin2hex(random_bytes(8));  // Gera cnonce aleatório
        }

        // 1. Calcular o HA1
        $HA1 = md5("{$username}:{$realm}:{$password}");

        // 2. Calcular o HA2
        $HA2 = md5("{$method}:{$uri}");

        // 3. Calcular o response
        $response = md5("{$HA1}:{$nonce}:{$nc}:{$cnonce}:{$qop}:{$HA2}");

        // 4. Montar o cabeçalho Proxy-Authorization
        $authHeader = sprintf(
            'Digest username="%s", realm="%s", nonce="%s", uri="%s", response="%s", cnonce="%s", qop=%s, nc=%s, algorithm=MD5',
            $username,
            $realm,
            $nonce,
            $uri,
            $response,
            $cnonce,
            $qop,
            $nc
        );

        return $authHeader;
    }

    public function logout(): bool
    {
        $modelRegister = $this->modelRegister();
        $modelRegister['headers']['Expires'] = ['0'];
        $this->socket->sendto($this->host, $this->port, $this->renderSolution($modelRegister));
        $this->isRegistered = false;
        return true;
    }

}


if (!function_exists('value')) {
    function value($string, $start, $end): ?string
    {
        return @explode($end, @explode($start, $string)[1])[0];
    }
}