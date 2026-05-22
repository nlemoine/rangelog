<!--
Fixture: v-prefix and bracket+date ATX heading shapes
Source: top section verbatim from https://raw.githubusercontent.com/thephpleague/commonmark/2.8/CHANGELOG.md (bracket+date entries 2.8.2 and 2.8.1); v-prefix `## v8.0.9` and `## v8.0.8` entries hand-crafted to model symfony-style v-prefixed ATX headings
Captured: 2026-05-12
Tests: v-prefix AND date suffix; bracket+date variant
License: BSD-3-Clause (league/commonmark by Colin O'Dell) for the 2.8.x entries; CC0 for hand-written v8.x entries
-->
## v8.0.9

* Make `AsCommand` attribute class `final`
* Ensure closures set via `Command::setCode()` method have proper parameter and return types

## v8.0.8

* Add argument `$finishedIndicator` to `ProgressIndicator::finish()`

## [2.8.2] - 2026-03-19

This is a **security release** to address an issue where the `allowed_domains` setting for the `Embed` extension can be bypassed, resulting in a possible SSRF and XSS vulnerabilities.

### Fixed
- Fixed `DomainFilteringAdapter` hostname boundary bypass where domains like `youtube.com.evil` could match an allowlist entry for `youtube.com` (GHSA-hh8v-hgvp-g3f5)

## [2.8.1] - 2026-03-05

This is a **security release** to address an issue where `DisallowedRawHtml` can be bypassed, resulting in a possible cross-site scripting (XSS) vulnerability.

### Fixed
- Fixed `DisallowedRawHtmlRenderer` not blocking raw HTML tags with trailing ASCII whitespace (GHSA-4v6x-c7xx-hw9f)
- Fixed PHP 8.5 deprecation (#1107)
