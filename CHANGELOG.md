# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2025-12-30

### Added

- Dynamic volume control during calls using `GainNode` and visual
  sliders. [[cd54e00](https://github.com/berzersks/spechphone/commit/cd54e001e4e76266c370d49e4da72dbafbe255bd), [187a4e4](https://github.com/berzersks/spechphone/commit/187a4e4f881bb299c97144c30c2a9e0fc6fdafcf)]
- Persistence of audio settings (microphone, speaker, volume) in
  localStorage. [[cd54e00](https://github.com/berzersks/spechphone/commit/cd54e001e4e76266c370d49e4da72dbafbe255bd)]
- Real-time call timer and SIP event notifications (callAccept, bye) in the
  UI. [[5127a53](https://github.com/berzersks/spechphone/commit/5127a53de5b05d3e7cba9d233eb8cbc1daf3fe04)]
- Comprehensive audio manipulation examples (resampling, codecs PCMA/PCMU/G.729, mixing) and streaming tools in the
  `extra` library. [[6e4deec](https://github.com/berzersks/spechphone/commit/6e4deec93dedfceb68447f375bb32e54bdd56850)]

### Changed

- Improved dialer interface with better positioning of call timers and responsive
  sliders. [[5127a53](https://github.com/berzersks/spechphone/commit/5127a53de5b05d3e7cba9d233eb8cbc1daf3fe04), [cd54e00](https://github.com/berzersks/spechphone/commit/cd54e001e4e76266c370d49e4da72dbafbe255bd)]
- Optimized WebSocket handler for improved token management and connection
  monitoring. [[5127a53](https://github.com/berzersks/spechphone/commit/5127a53de5b05d3e7cba9d233eb8cbc1daf3fe04)]

### Removed

- Obsolete modules and scripts for file compression, download, upload, and account
  management. [[96d2c37](https://github.com/berzersks/spechphone/commit/96d2c378e6051d5532704bc73c81750d016e0c60)]
- Redundant call modals, replaced by inline
  notifications. [[5127a53](https://github.com/berzersks/spechphone/commit/5127a53de5b05d3e7cba9d233eb8cbc1daf3fe04)]

## [0.1.0] - 2025-12-28

### Added
- SIP call initiation handler (`startCall.php`) with validation and real-time WebSocket events. [[d0d9d66](https://github.com/berzersks/spechphone/commit/d0d9d66f6ef9a19c240a54d35cd8fe6c4869d2fe)]
- VOIP audio manipulation and WebSocket integration, including microphone capture and real-time mixing. [[cd69b96](https://github.com/berzersks/spechphone/commit/cd69b96bc3571a9fb3b8506b29ce9ea9166a8bc7), [bec51b3](https://github.com/berzersks/spechphone/commit/bec51b3453b2a2c976250ce8c37d1cbaaac4465c)]
- Validation for mandatory SIP configuration fields (`sipServer`, `sipUser`, `sipPass`) in `saveConfig.php`. [[cd39b85](https://github.com/berzersks/spechphone/commit/cd39b85511ae466f8f99e73dd0a3c9cd1e5c69f3), [407881f](https://github.com/berzersks/spechphone/commit/407881f1b16084b1588b991178a2fae5499cfc64)]
- New WebSocket message type `brand` for dynamic UI updates. [[cd39b85](https://github.com/berzersks/spechphone/commit/cd39b85511ae466f8f99e73dd0a3c9cd1e5c69f3)]
- UDP endpoint for sending decoded audio. [[bec51b3](https://github.com/berzersks/spechphone/commit/bec51b3453b2a2c976250ce8c37d1cbaaac4465c)]

### Changed
- Improved SIP registration using `trunkController` with better error handling. [[cd39b85](https://github.com/berzersks/spechphone/commit/cd39b85511ae466f8f99e73dd0a3c9cd1e5c69f3)]
- Updated `router.js` to handle CallID changes and audio context initialization. [[bec51b3](https://github.com/berzersks/spechphone/commit/bec51b3453b2a2c976250ce8c37d1cbaaac4465c)]
- Refactored connection timer management for better resource cleanup. [[d0d9d66](https://github.com/berzersks/spechphone/commit/d0d9d66f6ef9a19c240a54d35cd8fe6c4869d2fe)]

### Fixed
- Bug in `cache.php` regarding conditional removal of global keys. [[cd69b96](https://github.com/berzersks/spechphone/commit/cd69b96bc3571a9fb3b8506b29ce9ea9166a8bc7)]

[0.2.0]: https://github.com/berzersks/spechphone/commits/96d2c378e6051d5532704bc73c81750d016e0c60
[0.1.0]: https://github.com/berzersks/spechphone/commits/d0d9d66f6ef9a19c240a54d35cd8fe6c4869d2fe
