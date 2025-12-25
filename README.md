# SpechPhone 📞

Uma aplicação **VoIP SIP moderna** com interface estilo **PortSIP**, construída em PHP com Swoole para comunicação em tempo real. Suporta chamadas de voz, codecs múltiplos e gerenciamento de configurações SIP.

## ✨ Características

### Chamadas & Áudio
- ✅ **Discagem intuitiva** com teclado virtual (1-9, *, 0, #)
- ✅ **Múltiplos codecs**: PCMA, PCMU, G.729, Opus
- ✅ **Controles de áudio** em tempo real
  - Silenciar (Mute)
  - Colocar em espera (Hold)
  - Alterna speaker/microfone
- ✅ **Processamento de áudio**
  - Supressão de ruído
  - Cancelamento de eco
  - Controle automático de ganho (AGC)
  - Ajuste de volume independente

### Rede & SIP
- 📡 **Transporte SIP**: UDP, TCP, TLS
- 🌐 **NAT Traversal**: Suporte a STUN (Google STUN ou servidor customizado)
- 🔐 **Autenticação SIP** com usuário/domínio/senha
- 🔄 **Registro dinâmico** no servidor SIP
- 📊 **RTP raw** em UDP porta 9600
- 🌊 **WebSocket** para streaming de áudio

### Interface
- 🎨 **Dark mode** moderno com design PortSIP
- 📱 **Responsivo** (otimizado para mobile)
- 🎭 **3 abas principais**:
  1. **Chamada** - Discagem e controles de call
  2. **Áudio** - Ajustes de volume e processamento
  3. **Config** - Configurações SIP e rede

## 🏗️ Arquitetura

```
spechphone/
├── c.php                    # Servidor HTTP/UDP Swoole (RTP mixing & WAV stream)
├── middleware.php           # WebSocket server (lógica principal)
├── plugins/
│   ├── Request/             # Roteamento HTTP
│   │   └── pages/default.html   # Interface UI (dialer + config)
│   ├── Database/            # SQLite + cache
│   ├── Extension/           # Utilitários (curl, terminal, etc)
│   ├── Message/             # Handler de mensagens
│   └── Start/               # Inicialização & console
├── libspech/                # Biblioteca SIP/RTP
│   ├── plugins/             # Codecs (G.729, Opus)
│   └── stubs/               # Channel handlers
├── js/                      # Frontend (jQuery, toast, router)
└── css/                     # Styling (dark mode, responsive)
```

## 🚀 Instalação & Execução

### Pré-requisitos
- **PHP** 7.4+ com Swoole
- **libspech** (incluído em `/libspech/`)
- **Node.js** (opcional, para build de assets)

### Setup Rápido

```bash
# Clonar projeto
git clone https://github.com/seu-usuario/spechphone.git
cd spechphone

# Instalar dependências PHP (se houver composer.json)
composer install

# Iniciar servidor WebSocket
php middleware.php
```

### Puertos Utilizados
- **8080** - HTTP Interface (UI)
- **4043** - WebSocket (SIP logic)
- **9600** - UDP (RTP streaming)

### Configuração Inicial

1. **Abrir interface**: http://localhost:8080
2. **Aba "Config"**: Preencher credenciais SIP
   - Servidor: `sip.seu-dominio.com:5060`
   - Usuário: `1001`
   - Domínio: `seu-dominio.com`
   - Senha: [sua senha]
3. **Salvar** → Status "Registrado"
4. **Aba "Chamada"**: Discar número e clicar em **LIGAR**

## 📡 Como Funciona

### Fluxo RTP/Audio
1. **UDP Port 9600** recebe pacotes RTP brutos
2. **Decoder**: Extrai PCM de múltiplos codecs (G.729, Opus, etc)
3. **Mixer**: Normaliza e mistura streams de áudio
4. **WAV Stream**: Converte para WAV infinito (8kHz, 16-bit mono)
5. **HTTP Streaming**: Entrega via `c.php` para o player

### Fluxo SIP
1. **Registro**: Envia REGISTER ao servidor SIP
2. **INVITE**: Cria chamada com SDP offer
3. **RTP**: Inicia stream de áudio UDP
4. **BYE**: Encerra chamada

## 🎮 Controles de Teclado

| Tecla | Ação |
|-------|------|
| `0-9`, `*`, `#` | Discar |
| `Backspace` | Deletar último dígito |
| `Escape` | Limpar todos dígitos |
| `Enter` | Fazer chamada |

## 🔧 Configuração Avançada

### Alterar Codec Padrão
Editar `c.php` linha ~85:
```php
$targetRate = 8000;  // Taxa de amostragem
$codecName = 'PCMA'; // Codec padrão
```

### STUN Server Customizado
Na aba **Config**, seção **Rede**, ativar STUN e inserir:
```
stun:seu-stun-server:porta
```

### Memory Limit
Em `c.php`:
```php
ini_set('memory_limit', '1024M');  // Aumentar se necessário
```

## Bibliotecas Utilizadas

- **Swoole** - Server HTTP/WebSocket/UDP
- **libspech** - Stack SIP/RTP
- **jQuery** - Frontend interativo
- **Bootstrap Icons** - FontAwesome
- **Prism.js** - Syntax highlighting (opcional)

## Segurança

- Suporte a **TLS** para SIP
- Validação de entrada no dialer
- CORS adequado
- **Nota**: Senhas em localStorage (não usar em produção sem HTTPS)

## Troubleshooting

### Conexão RTP não estabelecida
- Verificar firewall (UDP 9600)
- Validar STUN server em Config
- Conferir logs WebSocket

### Sem áudio em chamada
- Verificar volumes em Aba Áudio
- Testar microfone do navegador
- Confirmar codec compatível (PCMU/PCMA default)

### Servidor não inicia
```bash
# Verificar permissões Swoole
php -m | grep swoole

# Testar porta ocupada
lsof -i :8080
```

## 📄 Licença

Incluído em `/libspech/LICENSE`

## 🤝 Contribuições

Issues e PRs bem-vindos! Consulte `CODE_OF_CONDUCT.md`

---

Desenvolvido por Spech Team  
*Última atualização: Dezembro 2025*

