<?php

declare(strict_types=1);

namespace n5s\Rangelog\Domain;

/**
 * A resolved source for a package's changelog.
 *
 * $type is an OPEN STRING — not a closed enum. Built-in identifiers ship as
 * constants on SourceTypes for caller convenience; third-party callers may
 * pass any string discriminator and register a matching parser in their
 * ParserRegistry.
 *
 * The trade-off: weaker static type-safety on $type values; stronger
 * extensibility for BYO source providers.
 */
final readonly class Source
{
    /**
     * @param array<string, mixed> $metadata       Optional extra context the
     *                                              provider attaches (e.g. WP slug,
     *                                              GitHub owner/repo, branch ref).
     * @param string|null          $prefetchedBody  Pre-fetched body from paginating
     *                                              resolvers (e.g. GitHubReleasesResolver
     *                                              concatenates all pages here).
     *                                              HttpFetcher::fetch() short-circuits and returns
     *                                              a RawResponse('application/json') without a
     *                                              PSR-18 network call when this is non-null.
     *                                              Content-type is hardcoded to application/json;
     *                                              only GitHub Releases uses prefetchedBody in v1.
     */
    public function __construct(
        public string $type,
        public string $url,
        public array $metadata = [],
        // Pre-fetched body from paginating resolvers; HttpFetcher short-circuits when non-null.
        public ?string $prefetchedBody = null,
    ) {
    }
}
