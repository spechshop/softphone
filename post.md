# SpechPhone: Softphone SIP Web com PHP (Swoole) e WebSockets 🚀

Olá, comunidade! 👋

Estou animado para compartilhar o status atual do **SpechPhone**, um softphone SIP web open-source que desafia o
convencional ao não utilizar WebRTC. Em vez disso, utilizamos o poder do **PHP com Swoole** no backend para
processamento de mídia em tempo real e **WebSockets** para o transporte de áudio PCM.

O projeto está evoluindo rapidamente e gostaria de mostrar como está a interface e as funcionalidades disponíveis hoje.

## 📱 Interface Principal (Dialer)

A interface de chamada foi projetada para ser intuitiva e responsiva. Temos um discador completo com feedback visual,
controles de volume independentes para microfone e retorno de áudio, além de indicadores de nível de sinal (VU meters).

![Interface de Chamada](./screenshot_call.png)
*Tela principal: Discador, controles de chamada e monitoramento de áudio.*

> **Bastidores Técnicos:**
> O front-end interage com handlers específicos para gerenciar o estado da chamada:
> - **`startCall.php`**: Ao discar, este handler cria uma corrotina Swoole dedicada que gerencia toda a sinalização
    SIP (INVITE) e aloca portas UDP dinâmicas para o RTP.
> - **`checkCall.php`**: Atua como um *heartbeat*, sincronizando o status da UI (timer, botões) com a corrotina no
    backend.
> - **`dtmf.php`**: O envio de tons utiliza o padrão RFC 2833, injetando eventos diretamente no stream RTP via PHP.
> - **`hangUpCall.php`**: Garante o encerramento limpo (BYE) e a liberação imediata dos recursos de áudio.
>
> 📄 plugins/Message/handlers/startCall.php:44
> ```php
> public static function resolve(Server $socket, array $model, int $fd): ?bool
> {
>    $data = $model['data'];
>    $vault = new \spechphoneVault('/data/spechphone/devices.vault', getenv('SPECH_VAULT_KEY_HEX'));
>    // ...
> ```

## 🎧 Suporte Avançado a Codecs

Uma das grandes vantagens da nossa arquitetura é o controle total sobre a transcodificação. O SpechPhone suporta
nativamente uma grande variedade de codecs, processados diretamente no backend PHP.

Na aba de Áudio, é possível visualizar e selecionar os codecs disponíveis:

* **G.729 & Opus:** Para alta compressão e qualidade.
* **PCMA/PCMU:** Padrões clássicos G.711.
* **Wideband:** G.722, Speex, L16 (até 48kHz).
* **GSM & iLBC:** Para cenários de banda estreita.

![Seleção de Codecs](./screenshot_audio.png)
*Painel de Codecs: Suporte a G.729, Opus, G.722, GSM e muito mais.*

> **Bastidores Técnicos:**
> A mágica acontece na integração entre o `startCall.php` e o servidor de áudio `audio.php`:
> - **Pipeline de Áudio (Sem WebRTC)**: O navegador envia/recebe PCM raw via WebSocket (porta 8888) para o `audio.php`.
> - **Bridge UDP**: O `audio.php` encaminha esse áudio via UDP local (porta 9600) para a corrotina da chamada.
> - **Transcoding Nativo**: O PHP recebe o PCM, aplica a codificação selecionada (ex: comprime para G.729 ou Opus)
    usando a biblioteca `libspech` e envia para o tronco SIP. O processo inverso (decodificação) ocorre na chegada dos
    pacotes RTP, garantindo compatibilidade universal sem depender de codecs do browser.
>
> 📄 audio.php:54
> ```php
> $udp = new Swoole\Coroutine\Socket(AF_INET, SOCK_DGRAM, 0);
> $udp->bind("0.0.0.0", 9600);
> echo "🎧 Servidor UDP aguardando pacotes em 9600...\n";
> ```

## ⚙️ Configuração SIP Simplificada

Conectar-se ao seu servidor VoIP (Asterisk, FreeSWITCH, Opensips) é simples. A tela de configurações permite definir o
servidor, credenciais de autenticação e o codec preferencial para o tronco SIP.

![Configurações SIP](./screenshot_config.png)
*Configuração da conta SIP e preferências de conexão.*

> **Bastidores Técnicos:**
> A segurança e a validação são prioridades no handler **`saveConfig.php`**:
> - **Validação Ativa**: Antes de salvar, o sistema instancia um `trunkController` e tenta realizar um registro real (
    SIP REGISTER) no servidor.
> - **Feedback Imediato**: Se as credenciais estiverem erradas, o usuário é notificado na hora, evitando configurações "
    quebradas".
> - **Persistência Segura**: Apenas após o sucesso (200 OK), os dados são criptografados e armazenados no
    `devices.vault`. O handler **`register.php`** pode então ser usado para manter a sessão ativa (keep-alive).
>
> 📄 plugins/Message/handlers/saveConfig.php:103
> ```php
> if (!$trunkController->register(5)) {
>    return $socket->push($fd, json_encode([
>        'type' => 'notify',
>        'data' => [
>            'type' => 'bg-danger text-white',
>            'message' => "Registro falhou, verifique as credenciais fornecidas"
>        ]
>    ]));
> }
> ```

---

O projeto está em fase **Beta** e o desenvolvimento continua ativo, focado agora em aprimorar a estabilidade e
implementar o recebimento de chamadas.

🔗 **Confira o código e contribua no GitHub:
** [https://github.com/spechshop/spechphone](https://github.com/spechshop/spechphone)

Fiquem ligados para mais novidades! 🚀
