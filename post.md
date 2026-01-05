## VoIP Real-Time e Áudio 48 kHz no PHP: Guia Hands-On com Swoole

Este guia demonstra como construir um softphone completo com áudio de alta qualidade (48 kHz) usando PHP moderno com
Swoole. Vamos explorar VoIP e processamento de áudio em tempo real através de uma implementação prática.

> **Nota importante sobre arquitetura:** O SpechPhone que vamos explorar funciona de um jeito diferente do que você
> talvez esteja acostumado. Aqui, subimos **dois servidores PHP** (`middleware.php` e `audio.php`) que entregam áudio via
**RTP no backend + WebSocket (PCM) pro browser** — sem passar pelo caminho tradicional do **Asterisk/AGI**. É uma
> abordagem mais direta que facilita a compreensão do fluxo completo.
> Referência: https://github.com/spechshop/spechphone/blob/volume-dev/README.md

## O que você vai construir (de verdade)

Ao final deste guia, você terá nas mãos:

- **Um softphone web funcional** que faz SIP/RTP em tempo real, com interface no browser via WebSocket
- **Um pipeline de mídia completo** onde o backend recebe RTP, decodifica e envia PCM em chunks pro cliente
- **Uma implementação prática** de I/O assíncrono com corrotinas por sessão

E o melhor: você vai entender cada peça desse quebra-cabeça.

## 1) Entendendo a arquitetura

Vamos começar pelo básico: como tudo isso se encaixa? O SpechPhone é construído sobre **PHP + Swoole**, trabalhando com
**mídia em RTP/UDP** no backend e **PCM via WebSocket** direto pro browser. Nada de WebRTC/SRTP/ICE/DTLS aqui — é uma
abordagem mais crua e direta, o que facilita muito pra entender o que está acontecendo em cada camada.

Referência: https://github.com/spechshop/spechphone/blob/volume-dev/README.md

**Os dois pilares da aplicação:**

- **`middleware.php`**: servidor HTTP/WebSocket para interface web e controle de chamadas + servidor UDP para
  sinalização SIP
- **`audio.php`**: servidor HTTP/WebSocket + UDP que recebe streams RTP decodificados, mixa múltiplos canais de áudio e
  distribui via WebSocket para os clientes

Cada um tem seu papel bem definido, e você vai ver como eles conversam entre si.

Referência: https://github.com/spechshop/spechphone (seção "Directory Structure" do README)

## 2) O segredo do Swoole: corrotinas e I/O assíncrono

O conceito fundamental aqui é **I/O assíncrono com corrotinas**. Independente da linguagem, quando você domina esse
padrão, consegue lidar com operações em tempo real de forma eficiente.

O SpechPhone abre uma coroutine dedicada pra cada `trunkController`, o que significa que você pode ter várias chamadas
simultâneas rodando sem bloquear o processo principal. É a aplicação prática de concorrência cooperativa — um padrão que
funciona em qualquer ecossistema que o implemente.

Referência: https://github.com/spechshop/spechphone/blob/volume-dev/README.md

> **Detalhe importante:** Estamos falando de **Swoole** (a extensão/runtime oficial), não OpenSwoole. São projetos
> diferentes, então fique atento na hora de instalar.

## 3) Mão na massa: subir o SpechPhone localmente

Hora de colocar a mão no teclado. O README do SpechPhone já entrega tudo mastigado: clone, instale o runtime e suba os
servidores. Simples assim.

```bash
# deps
sudo apt update && sudo apt install -y openssl

# repo + lib
git clone https://github.com/spechshop/spechphone && cd spechphone
git clone https://github.com/spechshop/libspech

# runtime php otimizado (pcg729)
curl -L https://github.com/spechshop/pcg729/releases/download/current/php -o php
chmod +x ./php
sudo cp php /usr/local/bin/php

# start (em terminais separados)
php middleware.php
php audio.php
```

Referência do trecho acima: https://github.com/spechshop/spechphone/blob/volume-dev/README.md

**Dica prática:** Abra dois terminais lado a lado. Deixe os logs rolando e observe como os servidores se comunicam. É
bem instrutivo ver o fluxo acontecendo em tempo real.

## 4) Áudio 48 kHz: onde entra (de verdade) e por que isso importa

Aqui é onde a coisa fica interessante. Quando falamos de telefonia tradicional, você está preso a 8 kHz (aquele som
meio "de telefone", sabe?). Mas com Opus a 48 kHz, você tem qualidade de áudio próxima do que ouve em streaming de
música. É uma diferença que você **sente** na primeira chamada.

O SpechPhone trabalha com duas peças que se complementam:

**1) Oferta de Opus/48kHz via SDP** no `trunkController` (libspech). É aqui que você diz pro outro lado: "ei, eu falo
Opus em alta qualidade":

```php
// Oferecer codec Opus em SDP
$phone->mountLineCodecSDP('opus/48000/2');
```

Referência do snippet: https://github.com/spechshop/libspech/blob/spech/README.md

**2) Decodificação dinâmica + PCM em chunks pro browser**. Os pacotes RTP chegam, são processados pela `libspech` (
usando funções nativas via runtime `pcg729`), e o áudio decodificado é entregue ao controlador WebSocket em PCM puro,
pronto pra ser consumido no browser via `webkitAudioContext`.

Referência: https://github.com/spechshop/spechphone/blob/volume-dev/README.md

Em outras palavras: você negocia Opus, recebe RTP, decodifica e entrega PCM. Simples, direto e poderoso.

## 5) Um exemplo mínimo de chamada — vendo corrotinas e eventos na prática

Esse é o "hello world" da stack. Aqui você registra no servidor SIP, oferece o codec, reage a eventos (tocando,
atendido, desligado) e recebe áudio. Tudo isso em menos de 50 linhas:

```php
<?php
use libspech\Sip\trunkController;

include 'plugins/autoloader.php';

\Swoole\Coroutine\run(function () {
    $username = getenv('SIP_USERNAME');
    $password = getenv('SIP_PASSWORD');
    $domain   = getenv('SIP_DOMAIN');
    $host     = gethostbyname($domain);

    $phone = new trunkController($username, $password, $host, 5060);

    if (!$phone->register(2)) {
        throw new \Exception('Falha no registro');
    }

    // Oferecer codec Opus em SDP (48 kHz)
    $phone->mountLineCodecSDP('opus/48000/2');

    $phone->onRinging(function ($phone) {
        echo "Tocando...\n";
    });

    $phone->onAnswer(function (trunkController $phone) {
        echo "Atendido. Recebendo mídia...\n";
        $phone->receiveMedia();
        \Swoole\Coroutine::sleep(10);
    });

    $phone->onReceiveAudio(function ($pcmData, $peer, trunkController $phone) {
        echo "Recebido: " . strlen($pcmData) . " bytes\n";
    });

    $phone->onHangup(function (trunkController $phone) {
        echo "Chamada finalizada\n";
        $phone->close();
    });

    $phone->call('5511999999999');
});
```

Referência (onde esse exemplo está documentado): https://github.com/spechshop/libspech/blob/spech/README.md  
Referência do exemplo completo: https://github.com/spechshop/libspech/blob/spech/example.php

**O que está acontecendo aqui?** Você registra no servidor SIP, configura callbacks pra cada evento da chamada (ringing,
answer, hangup) e, quando atendido, começa a receber chunks de áudio PCM. Cada callback roda na sua própria coroutine,
sem travar nada.

## 6) Exercícios práticos (pra você realmente aprender fazendo)

Ler código é legal, mas mexer nele é melhor. O projeto inclui um diretório `stubs/` com esqueletos de código que mostram
as interfaces esperadas para canais de codec (`bcg729Channel.php`, `opusChannel.php`). Use-os como referência enquanto
experimenta.

**Exercício 1: Troque o codec e compare o comportamento**

Objetivo: Entender como diferentes codecs afetam o tamanho e a qualidade dos dados de áudio.

Passos:

1. No exemplo da seção 5, localize a linha `$phone->mountLineCodecSDP('opus/48000/2');`
2. Troque para `L16/8000` (PCM linear, 8 kHz, sem compressão)
3. Execute o script e observe a saída de `onReceiveAudio`
4. Compare:
    - Tamanho dos chunks recebidos (`strlen($pcmData)`)
    - Frequência de chegada dos pacotes
    - Qualidade percebida do áudio (se estiver reproduzindo)

O que você vai notar:

- `L16/8000` gera chunks maiores (sem compressão) e qualidade "telefônica"
- `opus/48000/2` gera chunks menores (comprimidos) com qualidade próxima a streaming de música

Referência: https://github.com/spechshop/libspech/blob/spech/README.md

**Exercício 2: Instrumente o fluxo de PCM e analise padrões**

Objetivo: Visualizar o comportamento do áudio em tempo real e identificar padrões de latência/jitter.

Passos:

1. Modifique o callback `onReceiveAudio` para registrar métricas detalhadas:

```php
$lastTime = null;
$phone->onReceiveAudio(function ($pcmData, $peer, trunkController $phone) use (&$lastTime) {
    $now = microtime(true);
    $size = strlen($pcmData);
    $interval = $lastTime ? ($now - $lastTime) * 1000 : 0; // ms
    
    echo sprintf(
        "[%s] Chunk: %d bytes | Intervalo: %.2f ms | Peer: %s\n",
        date('H:i:s'),
        $size,
        $interval,
        $peer
    );
    
    $lastTime = $now;
});
```

2. Execute uma chamada e observe os logs
3. Analise:
    - Tamanho médio dos chunks (deve ser consistente)
    - Intervalo entre pacotes (idealmente ~20ms para RTP padrão)
    - Variações (jitter) que podem indicar problemas de rede

Dica: Redirecione a saída para um arquivo e use ferramentas como `awk` ou planilhas para análise estatística.

Referência: https://github.com/spechshop/libspech/blob/spech/README.md

**Exercício 3: Faça a UI reagir aos eventos de chamada**

Objetivo: Conectar o backend ao frontend via WebSocket para criar uma interface interativa.

Passos:

1. No frontend (HTML/JS), conecte ao WebSocket do `middleware.php`
2. Escute os eventos enviados pelo backend: `ringing`, `answered`, `hangup`
3. Comece simples: apenas exiba os eventos no console do browser
4. Evolua: adicione indicadores visuais (ícones, cores, animações)

Exemplo básico (JavaScript):

```javascript
const ws = new WebSocket('wss://localhost:8080');

ws.onmessage = (event) => {
    const data = JSON.parse(event.data);

    if (data.event === 'ringing') {
        console.log('📞 Chamada tocando...');
        // Adicione animação de "tocando"
    } else if (data.event === 'answered') {
        console.log('✅ Chamada atendida');
        // Mude cor do botão, inicie timer
    } else if (data.event === 'hangup') {
        console.log('❌ Chamada finalizada');
        // Resete a UI
    }
};
```

Referência: https://github.com/spechshop/spechphone/blob/volume-dev/README.md

**Exercício 4 (Avançado): Explore os stubs e implemente um processador customizado**

Objetivo: Entender a interface de canais de codec e criar seu próprio processador de áudio.

Passos:

1. Examine os stubs em `stubs/opusChannel.php` e `stubs/bcg729Channel.php`
2. Identifique os métodos principais: `encode()`, `decode()`, `resample()`
3. Crie um processador simples que:
    - Recebe PCM no callback `onReceiveAudio`
    - Aplica um efeito básico (ex: redução de volume, inversão de fase)
    - Salva em arquivo ou reenvia via WebSocket

Exemplo de processador de volume:

```php
$phone->onReceiveAudio(function ($pcmData, $peer, trunkController $phone) {
    // PCM é int16, então cada sample = 2 bytes
    $samples = str_split($pcmData, 2);
    $processed = '';
    
    foreach ($samples as $sample) {
        $value = unpack('s', $sample)[1]; // int16
        $value = (int)($value * 0.5); // Reduz volume pela metade
        $processed .= pack('s', $value);
    }
    
    // Faça algo com $processed (salvar, enviar, etc)
    file_put_contents('output.pcm', $processed, FILE_APPEND);
});
```

Dica: Os stubs mostram métodos avançados como `enhanceVoiceClarity()` e `spatialStereoEnhance()` — explore-os para
entender o que é possível fazer com processamento de áudio em tempo real.

Referência dos stubs: `stubs/opusChannel.php`, `stubs/bcg729Channel.php`

## Links principais (pra você não se perder)

Aqui estão todos os recursos que você vai precisar:

- SpechPhone (repo): https://github.com/spechshop/spechphone
- SpechPhone (README, branch volume-dev): https://github.com/spechshop/spechphone/blob/volume-dev/README.md
- libspech (repo): https://github.com/spechshop/libspech
- libspech (README, branch spech): https://github.com/spechshop/libspech/blob/spech/README.md
- libspech (example.php): https://github.com/spechshop/libspech/blob/spech/example.php
- pcg729 (runtime release "current/php"): https://github.com/spechshop/pcg729/releases/tag/current

---

**E agora?** Clone o repo, suba os servidores e faça sua primeira chamada. Os conceitos apresentados aqui são aplicáveis
a qualquer stack que implemente I/O assíncrono com corrotinas. Boa sorte! 🚀
