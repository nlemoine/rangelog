<?php

declare(strict_types=1);

namespace n5s\Rangelog\Util;

use Composer\Semver\VersionParser;
use UnexpectedValueException;

/**
 * Stateless utility that derives candidate version-prefix branches from a
 * semver-shaped version string. Shared between the GitHub and GitLab file
 * resolvers: modern PHP ecosystem packages (Symfony 7.4, flysystem 3.x,
 * Laravel illuminate/* subtree-splits) maintain CHANGELOG.md on
 * per-major-line or per-minor-line branches, NOT on main/master. The same
 * convention applies on gitlab-hosted monorepos.
 *
 * The class is `final` (not `final readonly`) — it has no instance state;
 * the single public method is `static`.
 */
final class VersionBranchDeriver
{
    /**
     * Lightweight pattern to extract {major}.{minor} from a normalized semver string.
     * Called AFTER VersionParser::normalize() succeeds, so input is always digit-clean.
     */
    private const string VERSION_PATTERN = '/^(\d+)\.(\d+)(?:\.\d+)?/';

    /**
     * Derives candidate version-prefix branches from a semver-shaped version.
     *
     * Returns:
     *   - For '7.4.1'       → ['7.4', '7.x', '7.4.x']
     *   - For '3.33.0'      → ['3.33', '3.x', '3.33.x']
     *   - For '10.0.0-beta.1' → ['10.0', '10.x', '10.0.x'] (pre-release stripped via VersionParser::normalize)
     *   - For non-semver input (e.g. '20231015', '') → [] (caller falls back to INITIAL_BRANCHES only)
     *
     * Defense in depth: VersionParser::normalize() rejects any input that
     * isn't strict semver — including path-traversal attempts like '7.4/../etc/passwd'.
     * The post-normalize regex further constrains extraction to digit-only segments.
     *
     * @return list<string>
     */
    public static function deriveBranches(string $version): array
    {
        if ($version === '') {
            return [];
        }

        $parser = new VersionParser();
        try {
            $parser->normalize($version); // throws UnexpectedValueException on non-semver
        } catch (UnexpectedValueException) {
            // Non-semver range->to (date-versions, malformed) — fall back gracefully.
            return [];
        }

        if (preg_match(self::VERSION_PATTERN, $version, $m) !== 1) {
            return [];
        }

        $major = $m[1];
        $minor = $m[2];

        return ["{$major}.{$minor}", "{$major}.x", "{$major}.{$minor}.x"];
    }
}
