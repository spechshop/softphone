# SpechPhone 📞

SpechPhone is a high-performance **VoIP SIP application** built with **PHP** and **Swoole**. It features a modern PortSIP-inspired interface and supports real-time voice communication, multiple audio codecs, and dynamic SIP management.

## 🚀 Key Differentials

- **🎯 Zero External Dependencies**: No FFmpeg, SoX, or other media tools required - all audio processing is done
  natively in PHP
- **⚡ Pure PHP Audio Stack**: Native implementations of PCMA, PCMU, G.729, Opus, and L16 codecs via `libspech`
- **🎛️ Built-in Audio Processing**: Native resampling, mixing, noise suppression, echo cancellation, and AGC
- **🔊 Real-time Audio Enhancement**: Voice clarity enhancement and spatial stereo processing using Opus DSP
- **🌐 Fully Async Architecture**: Leverages Swoole coroutines for non-blocking I/O and concurrent connections
- **🔒 Self-contained**: All codec encoding/decoding, RTP handling, and SIP stack implemented in PHP

## ✨ Features

### Calls & Audio

- ✅ **Intuitive Dialer**: Virtual keypad with keyboard support
- ✅ **Multi-Codec Support**: PCMA, PCMU, G.729, Opus, and L16 - all implemented natively in PHP
- ✅ **Real-time Audio Controls**: Mute, Hold, Speaker/Microphone toggle, and dynamic volume control
- ✅ **Advanced Audio Processing**: Noise suppression, echo cancellation, AGC, resampling, and mixing - all done natively
  without external tools
- ✅ **Audio Enhancement**: Voice clarity enhancement and spatial stereo processing via Opus DSP

### Network & SIP
- **SIP Transport**: Support for UDP, TCP, and TLS.
- **NAT Traversal**: Integrated STUN support.
- **RTP Streaming**: Raw RTP over UDP and WebSocket audio streaming.
- **Secure Communication**: SSL/TLS support for both HTTP and WebSocket.

### Interface
- 🎨 **Modern Interface**: Responsive dark mode design.
- 🎭 **Modular Pages**: Dedicated sections for Call, Audio settings, and Configuration.

## 📸 Screenshots

|             Call Interface             |             Audio Interface              |              Configuration              |
|:--------------------------------------:|:----------------------------------------:|:---------------------------------------:|
| ![Call Interface](screenshot_call.png) | ![Audio Interface](screenshot_audio.png) | ![Configuration](screenshot_config.png) |

## 🛠 Tech Stack

- **Language**: PHP 7.4+
- **Server Engine**: [Swoole](https://www.swoole.co.uk/) (HTTP, WebSocket, UDP)
- **Frontend**: jQuery, Bootstrap, CSS/SASS/LESS
- **Core Library**: `libspech` (Custom SIP/RTP stack with native audio processing)
- **Data Storage**: Local cache and JSON-based configuration (SQLite support TODO)
- **Audio Processing**: Native PHP implementations (no FFmpeg/SoX dependencies)
- **Codecs**: PCMA, PCMU, G.729 (via bcg729), Opus, L16

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
- **OpenSSL**: Required for SSL/TLS features
- **That's it!** No FFmpeg, SoX, or external audio tools required

### Installation
1. **Clone the repository**:
   ```bash
   git clone https://github.com/spechshop/spechphone.git
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

- **Environment Check**: Verify your system meets requirements:
  ```bash
  php libspech/extra/tools/00_env_check.php
  ```
- **Codec Testing**: Test native codec implementations:
  ```bash
  php libspech/extra/codecs/01_pcma_pcmu_roundtrip.php
  ```
- **Audio Processing**: Test mixing, resampling, and enhancement:
  ```bash
  php libspech/extra/mixing/02_offsets_mix_3ways.php
  ```
- **Audio Quality**: SNR analysis and quality reports:
  ```bash
  php libspech/extra/quality/01_snr_report.php
  ```

See `libspech/EXTRA_AUDIO_TOOLS.md` for detailed examples of audio processing capabilities.

## 📜 License & Copyright

- **License**: See `libspech/LICENSE.txt`.
- **Copyright**: Refer to `libspech/COPYRIGHT_HEADER.txt`.

---
*Developed by Spech Team*  
*Last updated: December 2025*
