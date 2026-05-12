# SpechPhone

Open-source web softphone built with PHP + Swoole, focused on SIP signaling, RTP media, real-time media bridge,
`libspech` integration, and non-WebRTC architecture.

[![License](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](LICENSE.txt)
[![PHP Runtime](https://img.shields.io/badge/Runtime-pcg729-orange.svg)](https://github.com/spechshop/pcg729)
[![Swoole](https://img.shields.io/badge/Swoole-enabled-brightgreen.svg)](https://www.swoole.co.uk/)

[Português (Brasil)](README.pt-br.md)

## Why SpechPhone?

Most web-based softphone solutions rely on WebRTC — a feature-rich stack that introduces unnecessary complexity in
environments where SIP infrastructure is already in place: a PBX, a SIP trunk, or a VoIP provider.

**The problem SpechPhone solves:**

- Existing SIP environments that don't need WebRTC overhead
- Need for full control over the media and signaling stack
- Avoiding dependency on external STUN/TURN servers
- Integrating a web softphone into PHP stacks without rewriting existing infrastructure

**Why choose SpechPhone:**

- Fully PHP + Swoole stack — no Node.js, no transpilers
- Direct communication with SIP trunks via native UDP
- RTP ↔ PCM media bridge without WebRTC intermediaries
- Open-source, with no external service dependencies

## Current Status

The project now features advanced support for **inbound calls**, featuring a dedicated RTP ↔ PCM media bridge and
real-time state management. While the `inbound` branch is highly functional, it is still considered active/experimental
for production hardening.

- Full SIP inbound flow (INVITE/ACK/CANCEL/BYE).
- RTP ↔ PCM bridge using Swoole coroutines for high-performance media.
- Real-time messaging with backend storage and integrated chat UI.
- Advanced SIP session control via `trunkController`.

## Architecture Overview

SpechPhone follows a decentralized signaling and media architecture designed to operate without the complexity of
WebRTC.

### SIP Signaling & Control Plane

- **server.php**: The central entrypoint. It manages HTTPS, WSS, and SIP signaling (listening on UDP :4000). It handles
  packet parsing, transaction deduplication, and routing.
- **trunkController**: Located in `libspech`, this is the main orchestrator for SIP sessions. It manages sockets,
  registration, codec selection, SDP, RTP transport, and DTMF.
- **CallState**: A robust state manager using Swoole Tables to maintain real-time tracking of incoming calls, active
  sessions, and SIP user bindings.

### Media Engine

- **CallMediaBridge**: Orchestrates the RTP ↔ PCM flow. It bridges the SIP stack's RTP packets with the browser's PCM
  stream via a UDP relay.
- **audio.php**: A dedicated server that manages the browser's audio WebSocket (:8888) and the internal UDP relay (:
  9966). It handles jitter buffering, mixing, and resamping.
- **SdpHelper**: Helper for parsing remote SDP, selecting compatible codecs, and generating local SDP answers for
  session negotiation.
- **Swoole Coroutines**: The core of the media path, allowing asynchronous processing of RTP/PCM packets without
  blocking the signaling plane.

### Messaging System

- **messageStore**: Manages message persistence (`messages.json`), conversation listing, and history retrieval.
- **Real-time Notify**: New messages are pushed to connected clients instantly via WebSocket events (`messageNew`).

## Features

### Calls and SIP

- **Inbound Calls**: Reliable receiving of calls with proper Ringing state and `To` tag management.
- **SIP Dialog**: Proper handling of BYE/CANCEL with reverse routing (Record-Route) and correct headers (Contact).
- **Outbound Calls**: Full support for dialing via `trunkController`.
- **Registration**: SIP register and authentication flow with real-time status updates to the UI.

### Media and Codecs

- **RTP Media Bridge**: Bi-directional RTP ↔ PCM bridging using Swoole coroutines.
- **Codec Support**: Negotiation for PCMA, PCMU, G729, L16, and Opus (depending on environment and extensions).
- **DTMF**: Support for RFC2833 signaling.
- **Audio Relay**: Optimized transport between the SIP coroutines and the browser.

### Messaging

- **SIP MESSAGE**: Fully integrated send and receive path for SIP text messages.
- **Chat Interface**: Web-based chat UI with conversation history and list.
- **Message Management**: Mark messages as read and real-time delivery events.

### Frontend/UI

- **Modular Design**: Tabs for Dialer, Messaging, Audio Settings, and Configuration.
- **Real-time Updates**: Live call timers, signal meters, and notification system.
- **Dynamic Content**: Socket-driven page loading for a smooth SPA experience.

### Security and Runtime

- **Encryption**: Built-in support for WSS/HTTPS with automated SSL certificate generation.
- **Privacy**: No external WebRTC dependencies or STUN/TURN requirements for the media path.

## Requirements

- **Linux Environment** (Ubuntu/Debian recommended).
- **PHP 8.1+** with Swoole extension enabled.
- **OpenSSL** (required for WSS/HTTPS).
- **libspech** (must be fetched as a submodule).
- **Write Permissions**: `/data/spechphone` must exist and be writable by the PHP process.

## Installation

```bash
git clone https://github.com/spechshop/softphone
cd softphone
git clone https://github.com/spechshop/libspech
wget https://github.com/spechshop/pcg729/releases/download/PCG729/php
sudo mv php /usr/local/bin/php
sudo chmod +x /usr/local/bin/php
git submodule update --init --recursive

cp .env.example .env
# Open .env and set your SPECH_VAULT_KEY_HEX
```

> **Note on `pcg729`:** The binary above is a static PHP build with pre-compiled G.729 support. If you prefer not to use
> the static binary, you can clone the [pcg729](https://github.com/spechshop/pcg729) repository and compile normally — it
> is an adaptation of [Static PHP CLI (SPC)](https://github.com/crazywhalecc/static-php-cli), so it follows the same build
> process with the desired PHP extensions.

## Running

Launch both servers in separate terminals or as background processes:

```bash
# Main signaling and web server
php server.php

# Dedicated audio bridge server
php audio.php
```

*Note: `testInbound.php` is provided for development testing and should not be used as a primary entrypoint.*

## Configuration

### `plugins/configInterface.json`

Main runtime settings including host, port, SSL files, and plugin autoloading paths.

### Environment Variables

- `SPECH_VAULT_KEY_HEX`: Secret key used to encrypt/decrypt sensitive device configurations in `devices.vault`.

## Inbound Call Flow

1. **Configuration**: Device registers its SIP account and is mapped via `CallState`.
2. **INVITE**: `server.php` receives the SIP INVITE on UDP 4000.
3. **SDP Negotiation**: `SdpHelper` selects the best codec and `server.php` sends `180 Ringing`.
4. **Notification**: Browser receives an `incomingCall` event via WebSocket.
5. **Acceptance**: When the user accepts, a `200 OK` with local SDP is sent.
6. **Media Bridge**: `CallMediaBridge` starts a coroutine to bridge RTP ↔ PCM via the UDP relay.
7. **Audio Path**: Audio travels from `audio.php` (WSS 8888) to the browser.
8. **Hangup**: `BYE` is sent/received, terminating the dialog and cleaning up states.

## Security Notes

- Always use valid SSL certificates for production.
- Ensure your firewall allows UDP traffic for SIP (4000) and the dynamic RTP port range.
- Direct RTP communication requires visibility between the server and the SIP trunk/peer.

## Limitations

- **Experimental**: The `inbound` branch is in active development; expect updates and potential bugs.
- **NAT Traversal**: Depends on correct network configuration as WebRTC/ICE is not used.
- **DSP Features**: Advanced features like Echo Cancellation or Noise Suppression are currently planned or handled by
  the browser's hardware/OS layer.

## Screenshots

![Dialer Interface](docs/WhatsApp%20Image%202026-05-05%20at%2013.02.27%20(1).jpeg)
![Active Call with Minimized Bar](docs/WhatsApp%20Image%202026-05-05%20at%2013.02.27%20(2).jpeg)
![Messaging Interface](docs/WhatsApp%20Image%202026-05-05%20at%2013.02.27.jpeg)
![Mobile Call Interface](docs/WhatsApp%20Image%202026-05-05%20at%2013.00.45.jpeg)
![Mobile Floating Widget](docs/WhatsApp%20Image%202026-05-05%20at%2013.03.39.jpeg)

## License

Distributed under the **Apache License 2.0**.
Copyright © 2025 Lotus / berzersks.

Visit [spechshop.com](https://spechshop.com) for more info.
