# Automated testing and CI

The required PHP suites use the isolated `php-xmlwriter` executable from the latest stable `spechshop/pcg729` release. The workflow records the tag, GitHub asset id, executable SHA-256, PHP version, and runner architecture. A `workflow_dispatch` input accepts `stable`, `prerelease`, or an exact release tag for reproducibility. Missing assets, incompatible binaries, extensions, or APIs fail the job; the runner's system PHP is never selected as a fallback.

Run the installer and suites locally on Linux x86_64:

```bash
PCG729_RELEASE=stable bash .github/scripts/install-pcg729.sh
PCG729_PHP=/absolute/path/printed/by/installer/php-xmlwriter
"$PCG729_PHP" tests/SipRegisterManagerTest.php
"$PCG729_PHP" tests/RegistrarRetryPolicyTest.php
"$PCG729_PHP" tests/udp_sip_lab.php
```

Replace `PCG729_PHP` with the absolute path printed by the installer. The version in the example is illustrative; `stable` resolves through GitHub's releases API on every run. Generated extension stub declarations are excluded from PHP lint because the runtime itself defines those global functions; application code and tests still use the pcg729 parser.

`SIP Integration` binds the SpechPhone side to local UDP port 4000 and starts an isolated local UDP provider on an ephemeral port. It does not read `.env`, resolve a public SIP provider, or send packets outside loopback. The test requires port 4000 to be free. Existing `tests/real_sip_register.php` is an opt-in diagnostic for a user supplied provider and is not part of CI.

The fast checks run for pull requests targeting `inbound`, pushes to `inbound`, and manual dispatch. The bounded `Stress Tests` job is manual only and has a 15-minute job timeout. It uses local UDP, exercises 1, 10, 50, and 100 distinct Contacts sharing one AOR, and records datagrams/second, elapsed time, coroutines, pending transactions, memory, and process user CPU. Long duration soak testing and separate worker-process tests remain follow-up work.

After the workflow has run on GitHub, require `PHP Syntax`, `JavaScript Syntax`, `Unit Tests`, `SIP Integration`, `Regression Tests`, `Frontend Tests`, and `Security Checks` in branch protection for `inbound`. No coverage badge is configured because this repository does not currently produce a coverage report.
