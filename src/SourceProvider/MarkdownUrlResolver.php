<?php

declare(strict_types=1);

namespace n5s\Rangelog\SourceProvider;

use n5s\Rangelog\Domain\Package;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Domain\SourceTypes;
use n5s\Rangelog\Domain\VersionRange;
use n5s\Rangelog\Exception\ChangelogNotFoundException;
use n5s\Rangelog\Exception\FetchException;
use n5s\Rangelog\Fetcher\FetcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Generic terminal fallback that fetches the package's sourceUrl literally.
 *
 * By default `supports()` returns true unconditionally — this resolver
 * matches every Package. Chain-of-responsibility ordering (first-match-wins
 * per SourceProviderChain) makes this a fallback: place it LAST in any
 * chain that also includes host-specific resolvers, otherwise it will eat
 * URLs intended for downstream host-specific resolvers.
 *
 * **Security: optional host allowlist.** Because package metadata is
 * upstream-controlled (a malicious `composer.json`'s `support.source` can
 * point at any URL), the default "match everything" behaviour means a
 * crafted package can steer the library into fetching attacker-controlled
 * content with whatever credentials the caller's `AuthorizingFetcher` is
 * configured to attach. Pass `$allowedHosts` to constrain the resolver to
 * a known list of hosts: `supports()` returns false for hosts not in the
 * list, and the chain falls through to subsequent resolvers (or throws
 * `ChangelogNotFoundException`).
 *
 *   new MarkdownUrlResolver($fetcher, allowedHosts: [
 *       'raw.githubusercontent.com',
 *       'gitlab.com',
 *   ]);
 *
 * Host matching is exact (no automatic subdomain match), case-insensitive,
 * and port-agnostic — same semantics as `HostScopedCredentialProvider`.
 *
 * Fetches $package->sourceUrl literally. No filename variant scanning.
 * Callers point sourceUrl at the actual changelog file.
 *
 * On any FetchException, rethrows as ChangelogNotFoundException — this
 * resolver is the chain's last hope.
 */
final readonly class MarkdownUrlResolver implements SourceProviderInterface
{
    private LoggerInterface $logger;

    /**
     * @var list<string>|null  Lowercased allowed hosts, or null for "any host".
     */
    private ?array $allowedHosts;

    /**
     * @param list<string>|null $allowedHosts Optional host allowlist (exact
     *        match, case-insensitive). Pass `null` (default) for the
     *        permissive any-host behaviour. Pass `[]` to disable the
     *        resolver entirely.
     */
    public function __construct(
        private FetcherInterface $fetcher,
        ?LoggerInterface $logger = null,
        ?array $allowedHosts = null,
    ) {
        $this->logger = $logger ?? new NullLogger();

        $this->allowedHosts = $allowedHosts === null ? null : array_values(array_map(\strtolower(...), $allowedHosts));
    }

    public function supports(Package $package): bool
    {
        if ($this->allowedHosts === null) {
            return true;
        }

        $host = parse_url($package->sourceUrl, \PHP_URL_HOST);
        if (! \is_string($host) || $host === '') {
            return false;
        }

        return \in_array(strtolower($host), $this->allowedHosts, true);
    }

    public function resolve(Package $package, VersionRange $range): Source
    {
        $source = new Source(type: SourceTypes::MARKDOWN_URL, url: $package->sourceUrl);

        $this->logger->debug('Fetching markdown changelog from {url}', ['url' => $package->sourceUrl]);

        try {
            $this->fetcher->fetch($source);
        } catch (FetchException $e) {
            throw new ChangelogNotFoundException("Could not fetch changelog from {$package->sourceUrl}: HTTP {$e->statusCode}", $e->getCode(), previous: $e);
        }

        return $source;
    }
}
