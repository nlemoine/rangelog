<?php

declare(strict_types=1);

namespace n5s\Rangelog\Util;

/**
 * Value object identifying a GitHub repository by owner + repo. Constructed
 * either directly or via the {@see self::fromUrl()} static factory, which
 * normalises `.git` suffix and trailing slash and rejects non-github hosts.
 *
 * The regex anchors both ends so subdomains and userinfo cannot bypass the
 * host check (`notgithub.com` and `github.com.evil.com` both return null).
 */
final readonly class GitHubRepoUrl
{
    public function __construct(
        public string $owner,
        public string $repo,
    ) {
    }

    public static function fromUrl(string $url): ?self
    {
        if (\preg_match('#^https?://github\.com/([^/]+)/([^/]+?)(?:\.git)?/?$#i', $url, $m) !== 1) {
            return null;
        }

        return new self($m[1], $m[2]);
    }
}
