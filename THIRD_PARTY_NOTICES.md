# Third-party notices — application foundation

Project-owned code is Apache-2.0; see [LICENSE](LICENSE) and [NOTICE](NOTICE). The current dependency authority is app/composer.json and app/composer.lock. Third-party packages retain their own licenses, copyrights and disclaimers; no relicensing under the project license is intended.

| Runtime package | Version | Declared license | Preserved upstream files |
|---|---|---|---|
| composer/semver | 3.5.2 | MIT | [LICENSE](docs/licenses/runtime/composer/semver/LICENSE) |
| doctrine/dbal | 4.5.0 | MIT | [LICENSE](docs/licenses/runtime/doctrine/dbal/LICENSE) |
| doctrine/deprecations | 1.1.6 | MIT | [LICENSE](docs/licenses/runtime/doctrine/deprecations/LICENSE) |
| doctrine/lexer | 3.0.3 | MIT | [LICENSE](docs/licenses/runtime/doctrine/lexer/LICENSE) |
| egulias/email-validator | 4.0.4 | MIT | [LICENSE](docs/licenses/runtime/egulias/email-validator/LICENSE) |
| psr/cache | 3.0.0 | MIT | [LICENSE.txt](docs/licenses/runtime/psr/cache/LICENSE.txt) |
| psr/container | 2.0.2 | MIT | [LICENSE](docs/licenses/runtime/psr/container/LICENSE) |
| psr/event-dispatcher | 1.0.0 | MIT | [LICENSE](docs/licenses/runtime/psr/event-dispatcher/LICENSE) |
| psr/log | 3.0.2 | MIT | [LICENSE](docs/licenses/runtime/psr/log/LICENSE) |
| symfony/deprecation-contracts | v3.7.1 | MIT | [LICENSE](docs/licenses/runtime/symfony/deprecation-contracts/LICENSE) |
| symfony/event-dispatcher | v7.4.17 | MIT | [LICENSE](docs/licenses/runtime/symfony/event-dispatcher/LICENSE) |
| symfony/event-dispatcher-contracts | v3.7.1 | MIT | [LICENSE](docs/licenses/runtime/symfony/event-dispatcher-contracts/LICENSE) |
| symfony/http-foundation | v7.4.23 | MIT | [LICENSE](docs/licenses/runtime/symfony/http-foundation/LICENSE) |
| symfony/mailer | v7.4.22 | MIT | [LICENSE](docs/licenses/runtime/symfony/mailer/LICENSE) |
| symfony/mime | v7.4.22 | MIT | [LICENSE](docs/licenses/runtime/symfony/mime/LICENSE) |
| symfony/polyfill-ctype | v1.37.0 | MIT | [LICENSE](docs/licenses/runtime/symfony/polyfill-ctype/LICENSE) |
| symfony/polyfill-mbstring | v1.43.3 | MIT | [LICENSE](docs/licenses/runtime/symfony/polyfill-mbstring/LICENSE) |
| symfony/routing | v7.4.23 | MIT | [LICENSE](docs/licenses/runtime/symfony/routing/LICENSE) |
| symfony/service-contracts | v3.7.3 | MIT | [LICENSE](docs/licenses/runtime/symfony/service-contracts/LICENSE) |
| symfony/translation | v7.4.22 | MIT | [LICENSE](docs/licenses/runtime/symfony/translation/LICENSE) |
| symfony/translation-contracts | v3.7.1 | MIT | [LICENSE](docs/licenses/runtime/symfony/translation-contracts/LICENSE) |
| twig/twig | v3.30.0 | BSD-3-Clause | [LICENSE](docs/licenses/runtime/twig/twig/LICENSE) |

The generated Composer autoloader carries its own [MIT notice](docs/licenses/runtime/composer-generated-autoloader/LICENSE). The [runtime SPDX](docs/sbom/runtime.spdx.json) and [notice index](docs/licenses/runtime/INDEX.md) now refer to the actual root application runtime lock. This is not a released full forum or an image-wide SBOM. Preserve upstream texts and BSD-3-Clause endorsement restrictions.

This distribution contains runtime dependencies only. Developer tooling, research and AI/planning instructions are excluded. Preserve LICENSE, NOTICE, upstream vendor notices and the runtime license/SPDX files.
