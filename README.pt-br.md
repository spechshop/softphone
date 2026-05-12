# SpechPhone

Softphone web open-source construído com PHP + Swoole, focado em sinalização SIP, mídia RTP, ponte de mídia em tempo
real, integração com `libspech` e arquitetura sem WebRTC.

[![License](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](LICENSE.txt)
[![PHP Runtime](https://img.shields.io/badge/Runtime-pcg729-orange.svg)](https://github.com/spechshop/pcg729)
[![Swoole](https://img.shields.io/badge/Swoole-enabled-brightgreen.svg)](https://www.swoole.co.uk/)

[English](README.md)

## Por que o SpechPhone?

A maioria das soluções de softphone para web depende de WebRTC — uma pilha rica em recursos, mas que introduz
complexidade desnecessária quando você já possui infraestrutura SIP funcionando: um PABX, um tronco SIP ou um provedor
VoIP.

**O problema que o SpechPhone resolve:**

- Ambientes SIP já estabelecidos que não precisam do overhead do WebRTC
- Necessidade de controle total sobre o stack de mídia e sinalização
- Evitar dependência de servidores STUN/TURN externos
- Integrar um softphone web em stacks PHP sem reescrever a infraestrutura existente

**Por que usar o SpechPhone:**

- Pilha completamente em PHP + Swoole — sem Node.js, sem transpiladores
- Comunicação direta com troncos SIP via UDP nativo
- Ponte de mídia RTP ↔ PCM sem intermediários WebRTC
- Open-source, sem dependências de serviços externos

## Status Atual

O projeto agora possui suporte avançado para **chamadas inbound** (recebimento), contando com uma ponte de mídia RTP ↔
PCM dedicada e gerenciamento de estado em tempo real. Embora a branch `inbound` seja altamente funcional, ela ainda é
considerada ativa/experimental para uso em produção.

- Fluxo SIP inbound completo (INVITE/ACK/CANCEL/BYE).
- Ponte RTP ↔ PCM usando corrotinas Swoole para mídia de alta performance.
- Mensageria em tempo real com armazenamento no backend e interface de chat integrada.
- Controle avançado de sessão SIP via `trunkController`.

## Visão Geral da Arquitetura

O SpechPhone segue uma arquitetura descentralizada de sinalização e mídia, projetada para operar sem a complexidade do
WebRTC.

### Sinalização SIP e Plano de Controle

- **server.php**: O ponto de entrada central. Gerencia HTTPS, WSS e sinalização SIP (escutando em UDP :4000). Trata o
  parsing de pacotes, deduplicação de transações e roteamento.
- **trunkController**: Localizado na `libspech`, é o principal orquestrador de sessões SIP. Gerencia sockets, registro,
  seleção de codec, SDP, transporte RTP e DTMF.
- **CallState**: Um gerenciador de estado robusto que utiliza Swoole Tables para manter o rastreamento em tempo real de
  chamadas recebidas, sessões ativas e vínculos (bindings) de usuários SIP.

### Motor de Mídia (Media Engine)

- **CallMediaBridge**: Orquestra o fluxo RTP ↔ PCM. Faz a ponte entre os pacotes RTP da pilha SIP e o fluxo PCM do
  navegador via um relay UDP interno.
- **audio.php**: Um servidor dedicado que gerencia o WebSocket de áudio do navegador (:8888) e o relay UDP interno (:
  9966). Trata jitter buffering, mixagem e reamostragem (resampling).
- **SdpHelper**: Helper para parsing de SDP remoto, seleção de codecs compatíveis e geração de respostas SDP locais para
  negociação de sessão.
- **Corrotinas Swoole**: O núcleo do caminho de mídia, permitindo o processamento assíncrono de pacotes RTP/PCM sem
  bloquear o plano de sinalização.

### Sistema de Mensagens

- **messageStore**: Gerencia a persistência de mensagens (`messages.json`), listagem de conversas e recuperação de
  histórico.
- **Notificação em Tempo Real**: Novas mensagens são enviadas aos clientes conectados instantaneamente via eventos
  WebSocket (`messageNew`).

## Funcionalidades

### Chamadas e SIP

- **Chamadas Inbound**: Recebimento confiável de chamadas com estado de Ringing adequado e gerenciamento de tag `To`.
- **Diálogo SIP**: Tratamento correto de BYE/CANCEL com roteamento reverso (Record-Route) e cabeçalhos apropriados (
  Contact).
- **Chamadas Outbound**: Suporte total para discagem via `trunkController`.
- **Registro**: Fluxo de registro e autenticação SIP com atualizações de status em tempo real para a UI.

### Mídia e Codecs

- **Ponte de Mídia RTP**: Ponte bidirecional RTP ↔ PCM utilizando corrotinas Swoole.
- **Suporte a Codecs**: Negociação para PCMA, PCMU, G729, L16 e Opus (dependendo do ambiente e extensões).
- **DTMF**: Suporte para sinalização via RFC2833.
- **Relay de Áudio**: Transporte otimizado entre as corrotinas SIP e o navegador.

### Mensageria

- **SIP MESSAGE**: Caminho de envio e recebimento totalmente integrado para mensagens de texto SIP.
- **Interface de Chat**: Interface web com histórico e lista de conversas.
- **Gestão de Mensagens**: Marcação de leitura e eventos de entrega em tempo real.

### Frontend/UI

- **Design Modular**: Abas para Discador, Mensagens, Configurações de Áudio e Configuração do Sistema.
- **Atualizações em Tempo Real**: Timers de chamada ao vivo, medidores de sinal e sistema de notificações.
- **Conteúdo Dinâmico**: Carregamento de páginas via socket para uma experiência SPA fluida.

### Segurança e Runtime

- **Criptografia**: Suporte nativo para WSS/HTTPS com geração automatizada de certificados SSL.
- **Privacidade**: Sem dependências externas de WebRTC ou requisitos de STUN/TURN para o caminho de mídia.

## Requisitos

- **Ambiente Linux** (Ubuntu/Debian recomendado).
- **PHP 8.1+** com extensão Swoole habilitada.
- **OpenSSL** (necessário para WSS/HTTPS).
- **libspech** (deve ser baixado como submódulo).
- **Permissões de Escrita**: O diretório `/data/spechphone` deve existir e ter permissão de escrita para o processo PHP.

## Instalação

```bash
git clone https://github.com/spechshop/softphone
cd softphone
git clone https://github.com/spechshop/libspech
wget https://github.com/spechshop/pcg729/releases/download/PCG729/php
sudo mv php /usr/local/bin/php
sudo chmod +x /usr/local/bin/php
git submodule update --init --recursive

cp .env.example .env
# Abra o .env e defina sua chave SPECH_VAULT_KEY_HEX
```

> **Nota sobre o `pcg729`:** O binário acima é um PHP estático com suporte a G.729 pré-compilado. Se preferir não usar o
> binário estático, você pode clonar o repositório [pcg729](https://github.com/spechshop/pcg729) e compilar normalmente —
> ele é uma adaptação do [Static PHP CLI (SPC)](https://github.com/crazywhalecc/static-php-cli), portanto segue o mesmo
> processo de build com as extensões PHP desejadas.

## Execução

Inicie ambos os servidores em terminais separados ou como processos em background:

```bash
# Servidor principal de sinalização e web
php server.php

# Servidor de ponte de áudio dedicado
php audio.php
```

*Nota: O arquivo `testInbound.php` é fornecido para testes de desenvolvimento e não deve ser usado como ponto de entrada
principal.*

## Configuração

### `plugins/configInterface.json`

Configurações principais de tempo de execução, incluindo host, porta, arquivos SSL e caminhos de autoload de plugins.

### Variáveis de Ambiente

- `SPECH_VAULT_KEY_HEX`: Chave secreta usada para criptografar/descriptografar configurações sensíveis de dispositivos
  no `devices.vault`.

## Fluxo de Chamada Inbound

1. **Configuração**: O dispositivo registra sua conta SIP e é mapeado via `CallState`.
2. **INVITE**: O `server.php` recebe o INVITE SIP no UDP 4000.
3. **Negociação SDP**: O `SdpHelper` seleciona o melhor codec e o `server.php` envia `180 Ringing`.
4. **Notificação**: O navegador recebe um evento `incomingCall` via WebSocket.
5. **Aceitação**: Quando o usuário aceita, um `200 OK` com o SDP local é enviado.
6. **Ponte de Mídia**: O `CallMediaBridge` inicia uma corrotina para fazer a ponte RTP ↔ PCM via relay UDP.
7. **Caminho de Áudio**: O áudio viaja do `audio.php` (WSS 8888) para o navegador.
8. **Desligamento**: O `BYE` é enviado/recebido, encerrando o diálogo e limpando os estados.

## Notas de Segurança

- Sempre use certificados SSL válidos em produção.
- Certifique-se de que seu firewall permite tráfego UDP para SIP (4000) e para a faixa dinâmica de portas RTP.
- A comunicação RTP direta requer visibilidade entre o servidor e o tronco/peer SIP.

## Limitações

- **Experimental**: A branch `inbound` está em desenvolvimento ativo; espere atualizações e possíveis bugs.
- **NAT**: Depende de configuração de rede correta, já que WebRTC/ICE não são utilizados.
- **Recursos de DSP**: Recursos avançados como Cancelamento de Eco ou Supressão de Ruído estão planejados ou são
  gerenciados pela camada de hardware/OS do navegador.

## Capturas de Tela

![Interface do Discador](docs/WhatsApp%20Image%202026-05-05%20at%2013.02.27%20(1).jpeg)
![Chamada Ativa com Barra Minimizada](docs/WhatsApp%20Image%202026-05-05%20at%2013.02.27%20(2).jpeg)
![Interface de Mensagens](docs/WhatsApp%20Image%202026-05-05%20at%2013.02.27.jpeg)
![Interface de Chamada Mobile](docs/WhatsApp%20Image%202026-05-05%20at%2013.00.45.jpeg)
![Widget Flutuante Mobile](docs/WhatsApp%20Image%202026-05-05%20at%2013.03.39.jpeg)

## Licença

Distribuído sob a **Licença Apache 2.0**.
Copyright © 2025 Lotus / berzersks.

Visite [spechshop.com](https://spechshop.com) para mais informações.
