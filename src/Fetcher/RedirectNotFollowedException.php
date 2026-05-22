<?php

declare(strict_types=1);

namespace n5s\Rangelog\Fetcher;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * Raised by {@see NoRedirectClient} when the inner PSR-18 client returns a
 * redirect status (301, 302, 303, 307, 308) instead of auto-following it.
 *
 * Implements PSR-18's {@see ClientExceptionInterface} so that
 * {@see HttpFetcher}'s existing handler catches it and wraps it as a
 * `FetchException(statusCode=0, previous=this)`. Callers can identify
 * redirect-related failures by `instanceof`-checking
 * `$fetchException->getPrevious()`.
 */
final class RedirectNotFollowedException extends RuntimeException implements ClientExceptionInterface
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $requestUrl,
        public readonly ?string $location,
    ) {
        $msg = $location !== null
            ? "HTTP {$statusCode} redirect from {$requestUrl} to {$location} not followed"
            : "HTTP {$statusCode} redirect from {$requestUrl} (no Location header) not followed";

        parent::__construct($msg);
    }
}
