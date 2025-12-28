# SpechPhone 📞

SpechPhone is a high-performance **VoIP SIP application** built with **PHP** and **Swoole**. It features a modern PortSIP-inspired interface and supports real-time voice communication, multiple audio codecs, and dynamic SIP management.

## ✨ Features

### Calls & Audio
- ✅ **Intuitive Dialer**: Virtual keypad with keyboard support.
- ✅ **Multi-Codec Support**: PCMA, PCMU, G.729, and Opus.
- ✅ **Real-time Audio Controls**: Mute, Hold, and Speaker/Microphone toggle.
- ✅ **Advanced Audio Processing**: Noise suppression, echo cancellation, and AGC (via `libspech`).

### Network & SIP
- **SIP Transport**: Support for UDP, TCP, and TLS.
- **NAT Traversal**: Integrated STUN support.
- **RTP Streaming**: Raw RTP over UDP and WebSocket audio streaming.
- **Secure Communication**: SSL/TLS support for both HTTP and WebSocket.

### Interface
- 🎨 **Modern Interface**: Responsive dark mode design.
- 🎭 **Modular Pages**: Dedicated sections for Call, Audio settings, and Configuration.

## 🛠 Tech Stack

- **Language**: PHP 7.4+
- **Server Engine**: [Swoole](https://www.swoole.co.uk/) (HTTP, WebSocket, UDP)
- **Frontend**: jQuery, Bootstrap, CSS/SASS/LESS
- **Core Library**: `libspech` (Custom SIP/RTP stack)
- **Data Storage**: Local cache and JSON-based configuration (SQLite support TODO).

## 🏗 Project Structure

```text
spechphone/
├── middleware.php           # Main entry point: WebSocket & HTTP Server
├── audio.php                # Audio streaming & mixing server
├── plugins/                 # Application logic & modular system
│   ├── Extension/           # Utility plugins (cURL, terminal, etc.)
│   ├── Message/             # WebSocket message handlers
│   ├── OpenConnection/      # Connection management
│   ├── Request/             # HTTP routing, pages, and templates
│   ├── Start/               # Server initialization and CLI tools
│   └── Utils/               # Cache and buffering logic
├── libspech/                # Core SIP/RTP Library
│   ├── plugins/             # Codecs and network utilities
│   ├── extra/               # Examples and environment tools
│   └── stubs/               # Channel stubs
├── js/                      # Frontend JavaScript logic
├── css/                     # Styling (SASS/LESS/CSS)
└── plugins/configInterface.json # Main server configuration
```

## 🚀 Getting Started

### Prerequisites
- **PHP 7.4+**
- **Swoole Extension**: `php -m | grep swoole`
- **OpenSSL**: Required for SSL/TLS features.

### Installation
1. **Clone the repository**:
   ```bash
   git clone https://github.com/your-repo/spechphone.git
   cd spechphone
   ```
2. **Environment Setup**:
   Copy the example environment file (if available) or create a `.env` file:
   ```bash
   echo "SPECH_VAULT_KEY_HEX=your_secret_key" > .env
   ```

### Running the Application

The application consists of two main services that should be run:

1. **Main Server (SIP & UI)**:
   ```bash
   php middleware.php
   ```
   *Starts the WebSocket/HTTP server on the port defined in `plugins/configInterface.json`.*

2. **Audio Server**:
   ```bash
   php audio.php
   ```
   *Handles real-time audio streaming and mixing.*

## ⚙ Configuration

### Configuration File
Main settings are located in `plugins/configInterface.json`:
- `port`: Server port (default 443).
- `ssl`: Enable/disable SSL.
- `serverSettings`: Swoole-specific tuning (workers, coroutines, SSL certs).

### Environment Variables
Managed via the `.env` file:
- `SPECH_VAULT_KEY_HEX`: Key used for internal security and encryption.

## 🛠 Scripts & Tools

- **Interactive CLI**: `plugins/Start/console/cli.php` (contains a management menu).
- **Environment Check**: `php libspech/extra/tools/00_env_check.php` - Validates if your system meets the requirements for audio processing.

## 🧪 Testing

- **Automated Tests**: TODO (Add PHPUnit/Pest coverage).
- **Manual Verification**: Use examples in `libspech/extra/` to test codecs and streaming:
  ```bash
  php libspech/extra/codecs/01_pcma_pcmu_roundtrip.php
  ```

## 📜 License & Copyright

- **License**: See `libspech/LICENSE.txt`.
- **Copyright**: Refer to `libspech/COPYRIGHT_HEADER.txt`.

---
*Developed by Spech Team*  
*Last updated: December 2025*
