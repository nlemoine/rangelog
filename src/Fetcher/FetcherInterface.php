<?php

declare(strict_types=1);

namespace n5s\Rangelog\Fetcher;

use n5s\Rangelog\Domain\RawResponse;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Exception\FetchException;
use n5s\Rangelog\Exception\RateLimitedException;

/**
 * Public extension contract — fetch a RawResponse for a resolved Source.
 *
 * Implementations wrap PSR-18 clients (HttpFetcher), add caching
 * (CachingFetcher), retries, logging, or anything else a caller needs
 * by composing via FetcherStack.
 *
 * Implementations MUST:
 *  - Buffer PSR-7 response body to string (not stream)
 *  - Wrap PSR-18 ClientExceptionInterface as FetchException::previous
 *  - Throw RateLimitedException (NOT FetchException) on HTTP 429,
 *    populating retryAfter and rateLimitReset from response headers
 */
interface FetcherInterface
{
    /**
     * @throws FetchException        On any non-rate-limit network failure
     * @throws RateLimitedException  On HTTP 429 responses
     */
    public function fetch(Source $source): RawResponse;
}
