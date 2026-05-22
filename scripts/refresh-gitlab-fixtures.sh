#!/usr/bin/env bash
#
# Refresh GitLab HTTP fixtures from live gitlab.com sources.
#
# Targets:
#   A) GitLab Releases API — gitlab-org/release-cli (primary fixture)
#   B) GitLab Releases API — gitlab-org/security/cves (nested-group URL coverage)
#   C) GitLab Repository Files API — CHANGELOG.md from gitlab-org/release-cli@main
#
# Auth (GitLab rate-limit: 60/hr unauthenticated, 2000/hr with token):
#   export GITLAB_TOKEN=glpat-xxxx && scripts/refresh-gitlab-fixtures.sh
#   An empty GITLAB_TOKEN is harmless: GitLab ignores an empty PRIVATE-TOKEN header.
#
# Failure policy: on curl error (404, network error, non-2xx), committed fixtures
# are left untouched. The script streams curl output to a temp file and only mv's
# into place on curl success.
#
# Manual cadence — run before tagging a release or when resolver tests need
# refreshed real-world data. NOT run in CI.
#
# Hand-crafted fixtures NOT refreshed by this script:
#   tests/Fixtures/gitlab/releases/marketing-name.json
#   tests/Fixtures/gitlab/releases/non-semver-tag.json
#   tests/Fixtures/gitlab/releases/strict-boundary-tag.json
#   tests/Fixtures/gitlab/releases/release-cli-empty.json
#   tests/Fixtures/gitlab/releases/cap-hit-page10.json
#   tests/Fixtures/gitlab/releases/release-cli-page1-multi.json
#   tests/Fixtures/gitlab/releases/release-cli-page2.json
#   tests/Fixtures/gitlab/files/404-file-not-found.json
#   These are synthesized or hand-crafted to cover edge cases that live APIs
#   don't reproduce reliably. Review manually before release.

set -euo pipefail

# Resolve project root so the script works from any cwd.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

GL_RELEASES_DIR="tests/Fixtures/gitlab/releases"
GL_FILES_DIR="tests/Fixtures/gitlab/files"

mkdir -p "$GL_RELEASES_DIR" "$GL_FILES_DIR"

# Authorization header — empty string when GITLAB_TOKEN is not set.
# GitLab silently ignores an empty PRIVATE-TOKEN header value.
AUTH_HEADER="PRIVATE-TOKEN: ${GITLAB_TOKEN:-}"

# ---------------------------------------------------------------------------
# curl to temp file, mv on success, skip on error.
# Never overwrites committed fixtures on 404 / network error.
# ---------------------------------------------------------------------------

fetch_to_temp() {
    local url="$1"
    local out="$2"
    local tmp
    tmp="$(mktemp -t "refresh-gitlab-fixtures.XXXXXX")"
    # shellcheck disable=SC2064
    trap "rm -f '$tmp'" EXIT

    if curl -fsSL \
            -H "$AUTH_HEADER" \
            -H "Accept: application/json" \
            "$url" -o "$tmp" 2>/dev/null; then
        mv "$tmp" "$out"
        printf 'updated   %s  (from %s)\n' "$out" "$url"
    else
        printf 'skipped   %s  (curl error for %s)\n' "$out" "$url" 1>&2
        rm -f "$tmp"
    fi
}

fetch_file_to_temp() {
    local url="$1"
    local out="$2"
    local tmp
    tmp="$(mktemp -t "refresh-gitlab-fixtures.XXXXXX")"
    # shellcheck disable=SC2064
    trap "rm -f '$tmp'" EXIT

    if curl -fsSL \
            -H "$AUTH_HEADER" \
            "$url" -o "$tmp" 2>/dev/null; then
        mv "$tmp" "$out"
        printf 'updated   %s  (from %s)\n' "$out" "$url"
    else
        printf 'skipped   %s  (curl error for %s)\n' "$out" "$url" 1>&2
        rm -f "$tmp"
    fi
}

# ---------------------------------------------------------------------------
# Section A — GitLab Releases API: gitlab-org/release-cli (page 1)
# ---------------------------------------------------------------------------
echo "=== Section A: GitLab Releases API (gitlab-org/release-cli page 1) ==="

fetch_to_temp \
    'https://gitlab.com/api/v4/projects/gitlab-org%2Frelease-cli/releases?per_page=100&page=1&order_by=released_at&sort=desc' \
    "$GL_RELEASES_DIR/release-cli-page1.json"

# ---------------------------------------------------------------------------
# Section B — GitLab Releases API: gitlab-org/security/cves (nested-group)
# ---------------------------------------------------------------------------
echo ""
echo "=== Section B: GitLab Releases API (gitlab-org/security/cves — nested-group) ==="

# NOTE: gitlab-org/security/cves is chosen because it exercises the nested-group
# %2F URL encoding path (gitlab-org%2Fsecurity%2Fcves). This writes a NEW fixture
# filename (cves-nested-page1.json) not yet used by unit tests; available for
# future expansion of the nested-group coverage.
fetch_to_temp \
    'https://gitlab.com/api/v4/projects/gitlab-org%2Fsecurity%2Fcves/releases?per_page=100&page=1&order_by=released_at&sort=desc' \
    "$GL_RELEASES_DIR/cves-nested-page1.json"

# ---------------------------------------------------------------------------
# Section C — GitLab Repository Files API: CHANGELOG.md@main for release-cli
# ---------------------------------------------------------------------------
echo ""
echo "=== Section C: GitLab Repository Files API (gitlab-org/release-cli CHANGELOG.md@main) ==="

# NOTE: This uses the raw file endpoint which returns the file content directly.
# If release-cli does not have a CHANGELOG.md in the future, swap the project
# for another one with a CHANGELOG.md at main and update this comment.
fetch_file_to_temp \
    'https://gitlab.com/api/v4/projects/gitlab-org%2Frelease-cli/repository/files/CHANGELOG.md/raw?ref=main' \
    "$GL_FILES_DIR/release-cli-CHANGELOG.md"

echo ""
echo "Done. Review changes with: git status -- $GL_RELEASES_DIR $GL_FILES_DIR"
