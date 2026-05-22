<!--
Fixture: mixed valid + non-semver H2 sections
Source: hand-written
Captured: 2026-05-12
Tests: silent skip of non-semver versions; debug log per skipped header
License: hand-written (CC0)
-->
# Changelog

## 1.2.3

### Added

- Real semver entry — parser MUST emit one ChangelogEntry for this section.

### Fixed

- Crash on empty input.

## 2026-04-28

This section uses a calendar-date as its "version" identifier — composer/semver
will not accept this as a valid version, so the parser MUST silently skip it
and emit a `debug`-level "Skipping non-semver version" event.

- Date-versioned entries are common in changelog dialects that track release
  events rather than semver milestones.

## main-branch

This section uses a branch name as its "version" identifier — also rejected by
composer/semver, also silently skipped with a `debug` log event.

- Branch-named entries appear in repositories that ship rolling builds.
