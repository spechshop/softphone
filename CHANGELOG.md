# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[0.1.0]: https://github.com/berzersks/spechphone/commits/d0d9d66f6ef9a19c240a54d35cd8fe6c4869d2fe
