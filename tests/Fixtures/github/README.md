# GitHub Releases API fixtures

These fixtures back the unit tests for `GitHubReleasesParser`.

## `symfony-console-releases.json`

Real capture of the GitHub Releases REST API for `symfony/console`.
Captured 2026-05-12 with the following command:

```bash
curl -sH 'Accept: application/vnd.github+json' \
     -H 'X-GitHub-Api-Version: 2022-11-28' \
     'https://api.github.com/repos/symfony/console/releases?per_page=30' \
   > tests/Fixtures/github/symfony-console-releases.json
```

Covers the happy-path shape:

- `tag_name` populated and `v`-prefixed (`v8.0.0`, `v7.4.1`, …).
- `name == tag_name` for most entries.
- `published_at` populated as RFC-3339 timestamp.
- Multi-paragraph `body` per entry.
- ~30 releases spanning multiple major versions (ordering test material).

## `releases-with-nulls.json`

Hand-crafted 4-element array exercising every null branch the parser must handle:

1. `name: null` — parser falls back to `tag_name`.
2. `body: null` — parser coerces to empty string.
3. `published_at: null` AND `draft: true` AND `prerelease: true` — date becomes
   null in the emitted entry; in v1 we include drafts and prereleases.
4. `tag_name: "rolling"` — non-semver, parser MUST silently skip and emit
   a `debug` "Skipping non-semver version" record.

## Threat note

GitHub release bodies are untrusted markdown — the parser documents but does
not sanitize, preserving `body` verbatim in `ChangelogEntry.raw`. The
downstream `Renderer` is responsible for HTML escaping.

## Refresh policy

`symfony-console-releases.json` is a snapshot. Re-run the capture command above
manually when the parser tests need refreshed shape coverage. There is no
automated refresh script for GitHub fixtures (cf. `scripts/refresh-wp-fixtures.sh`
which only targets WP.org SVN).
