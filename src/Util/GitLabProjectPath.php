<?php

declare(strict_types=1);

namespace n5s\Rangelog\Util;

/**
 * Value object identifying a GitLab project by `$namespace` + `$project` plus
 * a pre-computed `$encodedPath` ready to drop into the GitLab REST API path
 * segment (`/api/v4/projects/{encodedPath}/...`).
 *
 * Constructed either directly or via the {@see self::fromUrl()} static
 * factory, which normalises the `.git` suffix and trailing slash and rejects
 * non-GitLab hosts via a two-step defense-in-depth check.
 *
 * - `$namespace` is the full path between host and project; for nested groups
 *   it contains forward slashes (e.g. `'gitlab-org/security'`).
 * - `$project` is the last path segment, with `.git` suffix stripped.
 * - `$encodedPath` is `rawurlencode("{namespace}/{project}")`, computed once
 *   at construction so resolvers never re-encode. Uses `rawurlencode` (RFC
 *   3986) exclusively — `urlencode` form-encodes spaces as `+` which the
 *   GitLab API rejects in path positions.
 *
 * The factory runs the host check twice (parse_url equality + anchored regex
 * with `preg_quote($host)`) so subdomain spoofs (`gitlab.com.evil.com`) and
 * suffix spoofs (`notgitlab.com`) cannot bypass the check.
 *
 * GitLab's browser-URL `/-/` namespace-collision sentinel (e.g.
 * `/group/project/-/tree/main`) is rejected naturally by the `$` anchor on
 * the optional `.git`/`/?` tail — only canonical repo URLs match.
 */
final readonly class GitLabProjectPath
{
    public function __construct(
        public string $namespace,
        public string $project,
        public string $encodedPath,
    ) {
    }

    public static function fromUrl(string $url, string $host = 'gitlab.com'): ?self
    {
        // Step 1: parse_url host equality (defense-in-depth).
        $parsedHost = parse_url($url, \PHP_URL_HOST);
        if (!\is_string($parsedHost) || strtolower($parsedHost) !== strtolower($host)) {
            return null;
        }

        // Step 2: anchored regex with preg_quote($host) injected so the host
        // appears literally; group 1 captures one-or-more `segment/` (the
        // namespace path), group 2 captures the project (final segment).
        //
        // A single-dash segment (`/-/`) is GitLab's namespace-collision sentinel
        // for browser-routed sub-resources (`/-/tree/main`, `/-/blob/...`,
        // `/-/issues`, etc.). Such URLs are NOT canonical repo identifiers
        // and must be rejected. The `(?!-/)` negative lookahead at each
        // namespace segment and the `(?!-)` guard on the project segment
        // forbid the bare `-` segment without affecting hyphenated names
        // like `gitlab-org` or `release-cli`.
        $pattern = \sprintf(
            '#^https?://%s/((?:(?!-/)[^/]+/)+)(?!-(?:\.git)?/?$)([^/]+?)(?:\.git)?/?$#i',
            preg_quote($host, '#'),
        );

        if (preg_match($pattern, $url, $m) !== 1) {
            return null;
        }

        $namespace = rtrim($m[1], '/');
        if ($namespace === '') {
            // Defensive: degenerate match where the namespace is empty after
            // the trailing-slash strip. Single-segment URLs are caught earlier
            // by the regex's `(?:[^/]+/)+` requirement, but this guard makes
            // the contract explicit.
            return null;
        }

        $project = $m[2];
        $encodedPath = rawurlencode("{$namespace}/{$project}");

        return new self($namespace, $project, $encodedPath);
    }
}
