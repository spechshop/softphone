[![License](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](LICENSE.txt)
[![PHP Runtime](https://img.shields.io/badge/Runtime-pcg729-orange.svg)](https://github.com/spechshop/pcg729)
[![Swoole](https://img.shields.io/badge/Swoole-enabled-brightgreen.svg)](https://www.swoole.co.uk/)
[![GitHub Repo Size](https://img.shields.io/github/repo-size/spechshop/spechphone)](https://github.com/spechshop/spechphone)
[![GitHub Last Commit](https://img.shields.io/github/last-commit/spechshop/spechphone)](https://github.com/spechshop/spechphone)
[![GitHub Issues](https://img.shields.io/github/issues/spechshop/spechphone)](https://github.com/spechshop/spechphone/issues)
[![GitHub Pull Requests](https://img.shields.io/github/issues-pr/spechshop/spechphone)](https://github.com/spechshop/spechphone/pulls)
[![GitHub stars](https://img.shields.io/github/stars/spechshop/spechphone)](https://github.com/spechshop/spechphone/stargazers)
[![GitHub forks](https://img.shields.io/github/forks/spechshop/spechphone)](https://github.com/spechshop/spechphone/network/members)

[Português (Brasil)](README.pt-br.md)

# SpechPhone

> **⚠️ IMPORTANT:** The project is still in **beta** and under development. Some features, such as **receiving calls**,
> are yet to be implemented.

## Architecture and Operation

SpechPhone was designed for high performance and ease of use in both development and production environments.

### Audio Processing and Coroutines

Through **Swoole**, the application opens a dedicated coroutine instance for each `trunkController` (SIP trunk manager).
This allows hundreds of calls to occur simultaneously without blocking the main process.

- **Dynamic Decoding:** When audio packets (RTP) arrive, they are processed by `libspech`, which identifies the codec (
  G.729, Opus, PCMA/U, L16) and performs dynamic real-time decoding via native C functions from the `pcg729` runtime.
- **PCM Delivery:** The decoded audio is delivered to the WebSocket controller in PCM **chunks**, ready to be consumed
  by the browser or other clients. SpechPhone **discards the use of WebRTC**, opting for a more direct approach where
  the audio is read in JavaScript via `webkitAudioContext`, ensuring extremely low latency and eliminating the
  complexity of the WebRTC stack.

### Security and SSL

To speed up local development, SpechPhone features an **automatic SSL key generation** system.

- If certificate files are not found at startup, the system uses OpenSSL to automatically generate **self-signed**
  certificates.
- This allows immediate use of HTTPS and WSS (Secure WebSockets), which are essential requirements for media operation
  in the browser (microphone) in secure contexts.

#### Security Model

- **No WebRTC:** SpechPhone does not use WebRTC (no SRTP / ICE / DTLS).
- **Backend Media:** Audio is transported via raw RTP (UDP) in the backend.
- **Thin Client:** The browser acts as a thin client via WebSocket (PCM delivery).
- **Production Requirements:** HTTPS and WSS are mandatory for production environments.
- **Firewall:** RTP ports should not be exposed to the public internet without a proper firewall.

## Requirements

- **Optimized PHP Runtime (pcg729)** – A static PHP CLI that includes native support for G.729, Opus, L16, C resampling
  functions, and other audio extensions. It is required for high-performance processing. It can
  be [downloaded here](https://github.com/spechshop/pcg729/releases/download/current/php) or built locally from
  the [official repository](https://github.com/spechshop/pcg729).
- **OpenSSL** for TLS/SSL features.
- Linux or macOS system is recommended for best compatibility.

> No external media tools are required; codecs and DSP are provided by the application itself.

## Installation and Execution

Installation is simple and straightforward. Follow the commands below to set up the environment:

```bash
# Install system dependencies
sudo apt update && sudo apt install -y openssl

# Clone the main repository and the codec library
git clone https://github.com/spechshop/spechphone && cd spechphone
git clone https://github.com/spechshop/libspech

# Obtain the pcg729 runtime (PHP optimized for audio)
# Using curl:
# curl -L https://github.com/spechshop/pcg729/releases/download/current/php -o php
# Or using wget: wget https://github.com/spechshop/pcg729/releases/download/current/php

# Configure and install the runtime
chmod +x ./php
sudo cp php /usr/local/bin/php

# Start the services (running in separate terminals is recommended)
php middleware.php
php audio.php
```

These commands set up the environment and start the necessary services. After execution, simply open the generated link
in your browser.

## What is SpechPhone?

SpechPhone is a modern **SIP/VoIP softphone** written in **PHP** and optimized with **Swoole**.  
It allows establishing real-time voice calls, controlling codecs, and integrating with backend tools without depending
on external media libraries.  
The implementation is entirely in PHP and follows high-performance principles with coroutines for asynchronous I/O.

## Features

- **Self-sufficient** – does not require FFmpeg, SoX, or other tools; all audio processing is done by the application
  itself.
- **Integrated Codecs** – support for PCMA, PCMU, G.729, Opus, and L16 via [
  `libspech`](https://github.com/spechshop/libspech). You can make calls with multiple audio formats without
  complications.
- **Embedded Audio Processing** – includes resampling, mixing, noise suppression, echo cancellation, and automatic gain
  control. These features are accessible via the PHP API.
- **Asynchronous I/O** – the use of Swoole coroutines eliminates blocking and allows hundreds of simultaneous sessions
  in a single process.
- **Full PHP Stack** – implements SIP, RTP, and media control within the code itself, avoiding additional compiled
  dependencies.
- **Easy Frontend Integration** – provides a responsive HTML/JS interface ready for browsers with **WebSocket** support.

## What can I build?

- **Enterprise Softphone** with dialer, shortcut keys, and browser-based workspace integration.
- **Proxy/SBC Client** for connecting to SIP servers via UDP.
- **Audio Streaming via WebSocket** or RTP for real-time dashboards.
- **Monitoring and Recording Services** using native audio utilities.

## Functionalities

### Calls and Media

- Dialer with keypad support and volume control.
- Negotiation of PCMA, PCMU, G.729, Opus, and L16 codecs.
- Mute, hold, speakerphone, and multi-channel mixing controls.
- Built-in DSP for noise suppression, echo cancellation, and clarity enhancement.

### Network and SIP

- SIP transport support via UDP.
- Raw RTP streaming over UDP and audio transport via WebSocket.
- HTTPS and WSS using OpenSSL.
- **NAT Traversal:** Optional support for **STUN** when configured.
- **TURN:** Not used or implemented.

### User Interface

- Responsive web interface with light/dark mode.
- Modular pages: Calls, Audio, Settings.
- Support for keyboard shortcuts and script control.

## Screenshots

![Call screen with dialer](screenshot_call.png)

![Audio controls and meters](screenshot_audio.png)

![Configuration panel](screenshot_config.png)

## Configuration

### File `plugins/configInterface.json`

Define ports, SSL options, number of Swoole workers, and other parameters for the main server.  
Example fields:

- `port`: HTTP/WS port (default 443).
- `ssl`: enable SSL (boolean).
- `serverSettings`: array of Swoole configurations (workers, certificates, etc.).

### Environment Variables

- **No Hardcoded Credentials:** There are no hardcoded credentials, tokens, or secrets in this repository.
- `SPECH_VAULT_KEY_HEX`: hexadecimal key used to encrypt sensitive configurations. It must be provided by the operator
  via the environment.

### Directory Structure

```
spechphone/
├── middleware.php           # HTTP/WS Server (UI and SIP)
├── audio.php                # Audio Server (mixing and streaming)
├── plugins/                 # Modular system (messages, connections, utilities)
│   ├── Extension/           # Utility plugins
│   ├── Message/             # WebSocket handlers
│   ├── OpenConnection/      # Connection management
│   ├── Request/             # HTTP routes and templates
│   ├── Start/               # Initialization (CLI, console)
│   └── Utils/               # Cache, buffers, and auxiliaries
├── libspech/                # SIP/RTP library and codecs (submodule)
├── js/                      # Frontend scripts
├── css/                     # Styles and themes
└── plugins/configInterface.json # Server configurations
```

## Additional Resources

- **SIP/RTP Library Logic:** signaling and codec functionalities are provided via [
  `libspech`](https://github.com/spechshop/libspech). Refer to that project's documentation to learn how call
  controllers, adaptive buffers, DTMF, etc., work.
- **Examples and Tools:** the `libspech/extra` directory contains auxiliary scripts to validate the environment, test
  codecs, and generate audio quality reports.
- **Interactive CLI:** access `plugins/Start/console/cli.php` to use management and status check menus.

## Contribution

Contributions are welcome! Before submitting a pull request:

- Keep copyright headers and licenses intact.
- Clearly describe your changes and the tests performed.
- Open an issue if you have questions or want to discuss a feature.

## License and Acknowledgments

This project is distributed under the **Apache License 2.0**.  
Copyright © 2025 Lotus / berzersks.

We thank the community for their support and look forward to your contributions to make SpechPhone even better.  
Visit <https://spechshop.com> for news and downloads.
