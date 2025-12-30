## O que é o SpechPhone?

SpechPhone é um **softphone SIP/VoIP** moderno escrito em **PHP** e otimizado com **Swoole**.  
Ele permite estabelecer chamadas de voz em tempo real, controlar codecs e integrar-se a
ferramentas de backend sem depender de bibliotecas de mídia externas.  
A implementação é totalmente em PHP e segue princípios de alta performance
com corrotinas para I/O assíncrono.

## Características

- **Autossuficiente** – não requer FFmpeg, SoX ou outras ferramentas; todo oprocessamento de áudio é feito pela própria
  aplicação.
- **Codecs integrados** – suporte a PCMA, PCMU, G.729, Opus e L16 via[
  `libspech`](https://github.com/spechshop/libspech). Você pode fazer chamadas com múltiplos formatos de áudio sem
  complicações.
- **Processamento de áudio embutido** – inclui reamostragem, mixagem, supressão deruído, cancelamento de eco e controle
  automático de ganho. Estes recursos são acessíveis a partir da API em PHP.
- **I/O assíncrono** – o uso de corrotinas Swoole elimina bloqueios epermite centenas de sessões simultâneas em um único
  processo.
- **Stack completo em PHP** – implementa SIP, RTP e controle de mídia nopróprio código, evitando dependências compiladas
  adicionais.
- **Fácil integração com front‑ends** - disponibiliza interface HTML/JS responsiva pronta para browsers com suporte a
  WebSocket.**

## O que posso construir?

- **Softphone empresarial** com discador, teclas de atalho e integração aoambiente de trabalho via navegador.
- **Proxy/SBC cliente** para conectar‑se a servidores SIP via UDP, TCP ou TLS.
- **Transmissão de áudio via WebSocket** ou RTP para dashboards em tempo real.
- **Serviços de monitoramento e gravação** usando utilitários nativos de áudio.

## Funcionalidades

### Chamadas e mídia

- Discador com suporte a teclado e controle de volume.
- Negociação de codecs PCMA, PCMU, G.729, Opus e L16.
- Controles de mudo, espera, viva‑voz e mixagem de múltiplos canais.
- DSP embutido para supressão de ruído, cancelamento de eco e realce de clareza.

### Rede e SIP

- Suporte a transporte SIP via UDP, TCP e TLS.
- STUN integrado para travessia de NAT (quando configurado).
- Streaming RTP cru por UDP e transporte de áudio via WebSocket.
- HTTPS e WSS usando OpenSSL.

### Interface do utilizador

- Interface web responsiva com modo claro/escuro.
- Páginas modulares: Chamadas, Áudio, Configurações.
- Suporte a atalhos de teclado e controle por scripts.

## Capturas de tela

![Tela de chamada com discador](screenshot_call.png)

![Controles de áudio e medidores](screenshot_audio.png)

![Painel de configuração](screenshot_config.png)

## Requisitos

- **PHP** 7.4 ou superior com suporte a **Swoole** (extensão `swoole` carregada).
- **OpenSSL** para recursos TLS.
- Sistema Linux ou macOS é recomendado para melhor compatibilidade.

> Nenhuma ferramenta externa de mídia é necessária; os codecs e DSP são fornecidos pela própria aplicação.

## Instalação e execução

1. **Clone o repositório:**
   ```bash git clone https://github.com/spechshop/spechphone.git cd spechphone ```

2. **Configure as variáveis de ambiente:**
   O serviço utiliza uma chave secreta (`SPECH_VAULT_KEY_HEX`) para criptografia. Crie um arquivo `.env` com sua chave
   ou copie um exemplo se houver:
   ```bash echo "SPECH_VAULT_KEY_HEX=000102030405060708090a0b0c0d0e0f" > .env ```

3. **Execute os serviços:**

- **Servidor principal (HTTP/SIP/UI)**      ```bash   php middleware.php   ```   O servidor HTTP/WS será iniciado na
  porta definida em   `plugins/configInterface.json`.
- **Servidor de áudio**      ```bash   php audio.php   ```   Responsável por streaming e mixagem de áudio em tempo real.

## Configuração

### Arquivo `plugins/configInterface.json`

Defina portas, opções SSL, número de workers Swoole e demais parâmetros para o
servidor principal.  
Exemplo de campos:

- `port`: porta HTTP/WS (padrão 443).
- `ssl`: habilitar SSL (booleano).
- `serverSettings`: array de configurações Swoole (workers, certificados, etc.).

### Variáveis de ambiente

- `SPECH_VAULT_KEY_HEX`: chave hexadecinal utilizada para criptografarconfigurações sensíveis.

### Estrutura de diretórios

```
spechphone/
├── middleware.php           # Servidor HTTP/WS (UI e SIP)
├── audio.php                # Servidor de áudio (mixagem e streaming)
├── plugins/                 # Sistema modular (mensagens, conexões, utilidades)
│   ├── Extension/           # Plugins utilitários
│   ├── Message/             # Handlers WebSocket
│   ├── OpenConnection/      # Gerenciamento de conexões
│   ├── Request/             # Rotas HTTP e templates
│   ├── Start/               # Inicialização (CLI, console)
│   └── Utils/               # Cache, buffers e auxiliares
├── libspech/                # Biblioteca SIP/RTP e codecs (submódulo)
├── js/                      # Scripts de frontend
├── css/                     # Estilos e temas
└── plugins/configInterface.json # Configurações do servidor
```

## Recursos adicionais

- **Lógica da biblioteca SIP/RTP:** as funcionalidades de sinalização e codecssão fornecidas via [
  `libspech`](https://github.com/spechshop/libspech). Consulte a documentação desse projeto para saber como funcionam os
  controladores de chamadas,buffers adaptativos, DTMF, etc.
- **Exemplos e ferramentas:** o diretório `libspech/extra` contém scriptsauxiliares para validar o ambiente, testar
  codecs e gerar relatórios dequalidade de áudio.
- **CLI interativo:** acesse `plugins/Start/console/cli.php` para utilizarmenus de gerenciamento e verificação de
  estado.

## Contribuição

Contribuições são bem‑vindas! Antes de enviar um pull request:

- Mantenha os cabeçalhos de copyright e licenças intactos.
- Descreva claramente suas mudanças e testes realizados.
- Abra uma issue se tiver dúvidas ou precisar discutir alguma funcionalidade.

## Licença e agradecimentos

Este projeto é distribuído sob a **Licença Apache 2.0**.  
Copyright © 2025 Lotus / berzersks.

Agradecemos à comunidade pelo apoio e esperamos suas contribuições para tornar o
SpechPhone ainda melhor.  
Visite <https://spechshop.com> para obter novidades e downloads.