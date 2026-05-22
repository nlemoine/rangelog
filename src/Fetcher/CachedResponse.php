<?php

declare(strict_types=1);

namespace n5s\Rangelog\Fetcher;

use n5s\Rangelog\Domain\RawResponse;

/**
 * @internal Storage tuple for {@see CachingFetcher}.
 *
 * Bundles a buffered upstream {@see RawResponse} with the ETag captured
 * from its `_response_etag` source metadata (or null when the upstream
 * response had no ETag header). Stored under a single PSR-16 cache key.
 *
 * Callers never see this type — {@see CachingFetcher::fetch()} always
 * unwraps it back to RawResponse.
 */
final readonly class CachedResponse
{
    public function __construct(
        public RawResponse $response,
        public ?string $etag,
    ) {
    }
}
