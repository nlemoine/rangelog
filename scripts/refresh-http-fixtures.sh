#!/usr/bin/env bash
#
# Refresh HTTP fixtures from live sources:
#   A) GitHub Releases API  — symfony/console pages 1-5
#   B) Packagist p2 API     — symfony/console
#   C) WordPress SVN        — akismet trunk readme.txt
#
# Run manually before tagging a release or when resolver tests need refreshed
# real-world data. NOT run in CI.
#
# Usage:
#   scripts/refresh-http-fixtures.sh
#
# Auth (GitHub rate-limit: 60/hr unauthenticated, 5000/hr authenticated):
#   export GITHUB_TOKEN=ghp_xxx && scripts/refresh-http-fixtures.sh
#
# On failure (404, network error, non-2xx), committed fixtures are left
# untouched: the script streams curl output to a temp file and only mv's into
# place on curl success.
#
# The with-changelog-url.json fixture is hand-crafted, NOT captured here.
# The page-of-100-template.json fixture is synthesized here via jq.

set -euo pipefail

# Resolve project root so the script works from any cwd
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

GH_RELEASES_DIR="tests/Fixtures/github/releases"
PACKAGIST_DIR="tests/Fixtures/packagist"
WP_DIR="tests/Fixtures/wp"

mkdir -p "$GH_RELEASES_DIR" "$PACKAGIST_DIR" "$WP_DIR"

# Authorization header — empty string when GITHUB_TOKEN is not set (GitHub ignores empty bearer)
AUTH_HEADER="Authorization: Bearer ${GITHUB_TOKEN:-}"

# ---------------------------------------------------------------------------
# Section A — GitHub Releases pages for symfony/console
# ---------------------------------------------------------------------------
echo "=== Section A: GitHub Releases (symfony/console) ==="

EMPTY_PAGE_FOUND=false
for page in 1 2 3 4 5; do
    url="https://api.github.com/repos/symfony/console/releases?per_page=100&page=${page}"
    out="${GH_RELEASES_DIR}/symfony-console-page-${page}.json"
    tmp="$(mktemp -t "refresh-http-fixtures.XXXXXX")"
    # shellcheck disable=SC2064
    trap "rm -f '$tmp'" EXIT

    if curl -fsSL \
            -H "$AUTH_HEADER" \
            -H "Accept: application/vnd.github+json" \
            "$url" -o "$tmp" 2>/dev/null; then
        # Detect empty array — this is the stop-condition page
        if php -r 'json_decode(file_get_contents($argv[1])) === [] ? exit(0) : exit(1);' "$tmp" 2>/dev/null || \
           (command -v jq >/dev/null 2>&1 && jq -e 'length == 0' "$tmp" >/dev/null 2>&1); then
            # Write empty.json (the canonical stop-condition fixture)
            if [ "$EMPTY_PAGE_FOUND" = "false" ]; then
                printf '[]' > "${GH_RELEASES_DIR}/empty.json"
                printf 'captured  %s  (empty page — stop signal)\n' "${GH_RELEASES_DIR}/empty.json"
                EMPTY_PAGE_FOUND=true
            fi
            printf 'skipped   %s  (empty page at page %d — stop)\n' "$out" "$page"
            rm -f "$tmp"
            break
        fi
        mv "$tmp" "$out"
        printf 'updated   %s  (from %s)\n' "$out" "$url"
    else
        printf 'skipped   %s  (curl error for %s)\n' "$out" "$url" 1>&2
        rm -f "$tmp"
    fi
done

# Ensure empty.json exists even if we never hit an empty page
if [ ! -f "${GH_RELEASES_DIR}/empty.json" ]; then
    printf '[]' > "${GH_RELEASES_DIR}/empty.json"
    printf 'created   %s  (empty-page fixture)\n' "${GH_RELEASES_DIR}/empty.json"
fi

# ---------------------------------------------------------------------------
# Section A2 — Synthesize page-of-100-template.json (truncation-signal test)
# ---------------------------------------------------------------------------
echo ""
echo "=== Section A2: Synthesize page-of-100-template.json ==="
TEMPLATE_OUT="${GH_RELEASES_DIR}/page-of-100-template.json"
tmp_template="$(mktemp -t "refresh-http-fixtures-template.XXXXXX")"
trap "rm -f '$tmp_template'" EXIT

if command -v jq >/dev/null 2>&1; then
    jq -n '[range(1;101) | {id: ., tag_name: ("v0.0." + (. | tostring)), name: ("v0.0." + (. | tostring)), published_at: "2026-01-01T00:00:00Z", body: "", draft: false, prerelease: false}]' > "$tmp_template"
else
    # Fallback: PHP one-liner
    php -r '
$entries = [];
for ($i = 1; $i <= 100; $i++) {
    $entries[] = ["id" => $i, "tag_name" => "v0.0.{$i}", "name" => "v0.0.{$i}", "published_at" => "2026-01-01T00:00:00Z", "body" => "", "draft" => false, "prerelease" => false];
}
echo json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
' > "$tmp_template"
fi

mv "$tmp_template" "$TEMPLATE_OUT"
printf 'created   %s  (100-entry truncation template)\n' "$TEMPLATE_OUT"

# ---------------------------------------------------------------------------
# Section B — Packagist p2 (symfony/console)
# ---------------------------------------------------------------------------
echo ""
echo "=== Section B: Packagist p2 (symfony/console) ==="

PACKAGIST_URL="https://repo.packagist.org/p2/symfony/console.json"
PACKAGIST_OUT="${PACKAGIST_DIR}/symfony-console.json"
tmp_pack="$(mktemp -t "refresh-http-fixtures-pack.XXXXXX")"
trap "rm -f '$tmp_pack'" EXIT

if curl -fsSL "$PACKAGIST_URL" -o "$tmp_pack" 2>/dev/null; then
    mv "$tmp_pack" "$PACKAGIST_OUT"
    printf 'updated   %s  (from %s)\n' "$PACKAGIST_OUT" "$PACKAGIST_URL"
else
    printf 'skipped   %s  (curl error for %s)\n' "$PACKAGIST_OUT" "$PACKAGIST_URL" 1>&2
    rm -f "$tmp_pack"
fi

# with-changelog-url.json is hand-crafted — do NOT overwrite
printf 'hand-crafted: %s/with-changelog-url.json — review manually\n' "$PACKAGIST_DIR"

# ---------------------------------------------------------------------------
# Section C — WordPress SVN (akismet trunk readme.txt)
# ---------------------------------------------------------------------------
echo ""
echo "=== Section C: WordPress SVN (akismet/trunk/readme.txt) ==="

WP_URL="https://plugins.svn.wordpress.org/akismet/trunk/readme.txt"
WP_OUT="${WP_DIR}/akismet-trunk-readme.txt"
tmp_wp="$(mktemp -t "refresh-http-fixtures-wp.XXXXXX")"
trap "rm -f '$tmp_wp'" EXIT

if curl -fsSL "$WP_URL" -o "$tmp_wp" 2>/dev/null; then
    mv "$tmp_wp" "$WP_OUT"
    printf 'updated   %s  (from %s)\n' "$WP_OUT" "$WP_URL"
else
    printf 'skipped   %s  (curl error for %s)\n' "$WP_OUT" "$WP_URL" 1>&2
    rm -f "$tmp_wp"
fi

echo ""
echo "Done. Review changes with: git status -- $GH_RELEASES_DIR $PACKAGIST_DIR $WP_DIR"
