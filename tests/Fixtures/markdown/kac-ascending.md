<!--
Fixture: Keep-a-Changelog ascending
Source: hand-written (same version content as kac-descending.md, reordered ascending; Unreleased omitted)
Captured: 2026-05-12
Tests: sort-equivalence — parser output must match kac-descending.md once both are normalized
License: hand-written (CC0)
-->
# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2025-12-01

### Added

- Initial public release of the `1.2.x` line.

### Changed

- Bumped minimum PHP to 8.3.

## [1.2.3] - 2026-01-15

### Added

- New `--quiet` flag to suppress non-error output.
- Support for Markdown reference-style links in entries.

### Fixed

- Crash when the input file contains UTF-8 BOM.
- Off-by-one error in pagination of long entries.

[1.2.0]: https://example.com/releases/tag/1.2.0
[1.2.3]: https://example.com/compare/1.2.0...1.2.3
