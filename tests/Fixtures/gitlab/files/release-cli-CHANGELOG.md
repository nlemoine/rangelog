# Changelog

All notable changes to the GitLab Release CLI are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Support for scheduled release creation via CI/CD pipelines.

## [0.16.0] - 2024-03-15

### Added

- New `--assets-link` flag to attach multiple asset links to a release.
- Support for release evidence collection at the point of release creation.
- Added `--ref` option to specify the tag or commit for the release.

### Fixed

- Fixed an issue where milestone associations were silently dropped on update.
- Resolved a race condition when creating releases on protected branches.

## [0.15.0] - 2024-01-22

### Added

- Initial support for release notes generated from merged merge requests.
- Added `--name` and `--description` flags for release metadata.
- Introduced `release create` and `release update` subcommands.

### Fixed

- Fixed tag creation failure when the pipeline was still running.
- Corrected exit code handling when the GitLab API returns a 4xx response.

[Unreleased]: https://gitlab.com/gitlab-org/release-cli/-/compare/v0.16.0...HEAD
[0.16.0]: https://gitlab.com/gitlab-org/release-cli/-/compare/v0.15.0...v0.16.0
[0.15.0]: https://gitlab.com/gitlab-org/release-cli/-/releases/v0.15.0
