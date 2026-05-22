#!/usr/bin/env bash
#
# Refresh WordPress plugin readme.txt and changelog.md fixtures from SVN trunk.
# Run manually before tagging a release or when parser tests need refreshed
# real-world data.
#
# Usage: scripts/refresh-wp-fixtures.sh
#
# Attempts both readme.txt AND changelog.md for every plugin: not all plugins
# ship changelog.md, so 404s are silently skipped. Files that return 200
# overwrite the committed fixture.
#
# On failure (404, network error), the committed fixture is left untouched:
# curl writes to a temp file and only mv's into place on success.

set -euo pipefail

PLUGINS=(
  "woocommerce"
  "wordpress-seo"
  "contact-form-7"
  "jetpack"
  "advanced-custom-fields"
)
FILES=("readme.txt" "changelog.md")
FIXTURE_DIR="tests/Fixtures/wp"

mkdir -p "$FIXTURE_DIR"

# Resolve project root so the script works from any cwd
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

for slug in "${PLUGINS[@]}"; do
  for file in "${FILES[@]}"; do
    url="https://plugins.svn.wordpress.org/${slug}/trunk/${file}"
    out="${FIXTURE_DIR}/${slug}.${file}"
    tmp="$(mktemp -t "refresh-wp-fixtures.XXXXXX")"
    trap 'rm -f "$tmp"' EXIT
    # -f: fail on HTTP error (exit non-zero on 404)
    # -s: silent  -L: follow redirects  -o: output file
    # --retry 3 --retry-delay 2: absorb transient network blips before declaring failure
    if curl -fsSL --retry 3 --retry-delay 2 "$url" -o "$tmp"; then
      mv "$tmp" "$out"
      printf 'updated  %s  (from %s)\n' "$out" "$url"
    else
      printf 'skipped  %s  (not found at %s)\n' "$out" "$url" 1>&2
    fi
  done
done

echo
echo "Done. Review changes with: git status -- $FIXTURE_DIR"
