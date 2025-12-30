# SpechPhone

SpechPhone é um **aplicativo SIP/VoIP de alto desempenho** em **PHP** com **Swoole**, inspirado na experiência do
PortSIP. Ele oferece chamadas em tempo real, múltiplos codecs de áudio nativos e uma interface moderna pronta para
produção.

## Por que escolher o SpechPhone

- **Sem dependências externas**: não precisa de FFmpeg, SoX ou outras ferramentas de mídia – todo o processamento é
  feito em PHP.
- **Pilha de áudio 100% PHP**: codecs PCMA, PCMU, G.729, Opus e L16 via `libspech`.
- **Processamento embutido**: reamostragem, mixagem, supressão de ruído, cancelamento de eco e AGC nativos.
- **Realce de voz em tempo real**: melhoria de clareza e espacialização estéreo com DSP do Opus.
- **Arquitetura assíncrona**: corrotinas Swoole para I/O não bloqueante e conexões concorrentes.
- **Stack autocontida**: codecs, RTP e SIP implementados no próprio projeto.

## O que você pode fazer

- Construir um softphone corporativo com discador web e teclas de atalho.
- Integrar rapidamente com proxies/SBCs SIP usando UDP, TCP ou TLS.
- Publicar streaming de áudio via RTP cru ou WebSocket para browsers e serviços.
- Habilitar monitoramento, gravação ou análise de áudio com os utilitários nativos do `libspech`.

## Recursos principais

### Chamadas e áudio

- Discador intuitivo com suporte a teclado.
- Suporte nativo a PCMA, PCMU, G.729, Opus e L16.
- Controles em tempo real: mudo, hold, viva-voz/microfone e volume dinâmico.
- Processamento avançado: supressão de ruído, cancelamento de eco, AGC, reamostragem e mixagem sem ferramentas externas.
- Realce de clareza e espacialização estéreo via DSP do Opus.

### Rede e SIP

- Transporte SIP via UDP, TCP e TLS.
- STUN integrado para atravessamento de NAT.
- Streaming RTP cru por UDP e áudio via WebSocket.
- Suporte a SSL/TLS para HTTP e WebSocket.

### Interface

- Design moderno responsivo com modo escuro.
- Páginas modulares para Chamadas, Áudio e Configurações.

## Capturas de tela

|          Interface de Chamada          |            Interface de Áudio            |              Configuração               |
|:--------------------------------------:|:----------------------------------------:|:---------------------------------------:|
| ![Call Interface](screenshot_call.png) | ![Audio Interface](screenshot_audio.png) | ![Configuration](screenshot_config.png) |

## Stack técnica

- **Linguagem**: PHP 7.4+
- **Engine**: [Swoole](https://www.swoole.co.uk/) (HTTP, WebSocket, UDP)
- **Frontend**: jQuery, Bootstrap, CSS/SASS/LESS
- **Biblioteca core**: `libspech` (SIP/RTP nativo com processamento de áudio)
- **Armazenamento**: cache local e configuração em JSON (suporte a SQLite planejado)
- **Processamento de áudio**: implementações PHP puras (sem FFmpeg/SoX)
- **Codecs**: PCMA, PCMU, G.729 (via bcg729), Opus, L16

## Estrutura do projeto

```text
spechphone/
├── middleware.php           # Entrada principal: servidor WebSocket/HTTP
├── audio.php                # Servidor de áudio para streaming e mixagem
├── plugins/                 # Lógica de aplicação e sistema modular
│   ├── Extension/           # Plugins utilitários (cURL, terminal etc.)
│   ├── Message/             # Handlers de mensagens WebSocket
│   ├── OpenConnection/      # Gerenciamento de conexões
│   ├── Request/             # Rotas HTTP, páginas e templates
│   ├── Start/               # Inicialização do servidor e CLI
│   └── Utils/               # Cache e lógica de buffers
├── libspech/                # Biblioteca core de SIP/RTP
│   ├── plugins/             # Codecs e utilitários de rede
│   ├── extra/               # Exemplos e ferramentas de ambiente
│   └── stubs/               # Stubs de canais
├── js/                      # Lógica JavaScript de frontend
├── css/                     # Estilos (SASS/LESS/CSS)
└── plugins/configInterface.json # Configuração principal do servidor
```

## Primeiros passos

### Pré-requisitos

- PHP 7.4 ou superior
- Extensão Swoole (`php -m | grep swoole`)
- OpenSSL para recursos SSL/TLS
- Nenhuma ferramenta externa de áudio é necessária

### Instalação

1. Clone o repositório:
   ```bash
   git clone https://github.com/spechshop/spechphone.git
   cd spechphone
   ```
2. Configure o ambiente criando um `.env` (ou copie o exemplo, se houver):
   ```bash
   echo "SPECH_VAULT_KEY_HEX=seu_segredo" > .env
   ```

### Execução

São dois serviços principais:

1. **Servidor principal (SIP e UI)**
   ```bash
   php middleware.php
   ```
   Inicia o servidor WebSocket/HTTP na porta definida em `plugins/configInterface.json`.

2. **Servidor de áudio**
   ```bash
   php audio.php
   ```
   Trata o streaming e a mixagem de áudio em tempo real.

## Configuração

### Arquivo `plugins/configInterface.json`

- `port`: porta do servidor (padrão 443)
- `ssl`: habilita/desabilita SSL
- `serverSettings`: ajustes Swoole (workers, corrotinas, certificados SSL)

### Variáveis de ambiente

- `SPECH_VAULT_KEY_HEX`: chave usada para segurança e criptografia interna

## Scripts e ferramentas

- **CLI interativo**: `plugins/Start/console/cli.php` (menu de gerenciamento).
- **Verificação de ambiente**: `php libspech/extra/tools/00_env_check.php` confirma requisitos de áudio.

## Testes rápidos

- Verificar ambiente:
  ```bash
  php libspech/extra/tools/00_env_check.php
  ```
- Testar codecs nativos:
  ```bash
  php libspech/extra/codecs/01_pcma_pcmu_roundtrip.php
  ```
- Testar mixagem/reamostragem/realce:
  ```bash
  php libspech/extra/mixing/02_offsets_mix_3ways.php
  ```
- Relatórios de qualidade (SNR):
  ```bash
  php libspech/extra/quality/01_snr_report.php
  ```

Consulte `libspech/EXTRA_AUDIO_TOOLS.md` para exemplos detalhados de processamento de áudio.

## Licença

- Consulte `libspech/LICENSE.txt`.
- Copyright conforme `libspech/COPYRIGHT_HEADER.txt`.

---
*Desenvolvido pela equipe Spech*  
*Última atualização: dezembro de 2025*
