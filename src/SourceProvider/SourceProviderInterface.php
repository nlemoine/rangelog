<?php

declare(strict_types=1);

namespace n5s\Rangelog\SourceProvider;

use n5s\Rangelog\Domain\Package;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Domain\VersionRange;
use n5s\Rangelog\Exception\ChangelogNotFoundException;
use n5s\Rangelog\Exception\FetchException;
use n5s\Rangelog\Exception\RateLimitedException;
use n5s\Rangelog\Exception\UnsupportedPackageException;

/**
 * Public extension contract — locate the changelog source for a package.
 *
 * "Source provider" is the user-facing name because that's what callers
 * are PROVIDING when they BYO. The library ships convenience defaults
 * (composer-support, GitHub Releases, GitHub file, WordPress.org).
 *
 * resolve() accepts a VersionRange so that resolvers can use $range->to
 * for tag-pinning (WordPressOrgResolver) and version-bounded pagination
 * (GitHubReleasesResolver).
 *
 * supports(Package) is the URL-host routing decision point. Implementations
 * inspect parse_url($package->sourceUrl)['host'] (case-insensitively via
 * strtolower) to decide whether to handle the package. The terminal-fallback
 * MarkdownUrlResolver returns true for every Package; chain placement
 * (first-match-wins per SourceProviderChain) is the caller's responsibility.
 *
 * Implementations:
 *  - supports(Package): bool — return true ONLY if this provider can
 *    resolve a Source for the given package. Non-support is signalled
 *    by returning false, NEVER by throwing.
 *  - resolve(Package, VersionRange): Source — only called after supports()
 *    returned true. May throw FetchException / RateLimitedException /
 *    UnsupportedPackageException / ChangelogNotFoundException for runtime failures.
 *
 * Implementations MUST be `final class`.
 */
interface SourceProviderInterface
{
    public function supports(Package $package): bool;

    /** @throws FetchException|RateLimitedException|UnsupportedPackageException|ChangelogNotFoundException */
    public function resolve(Package $package, VersionRange $range): Source;
}
