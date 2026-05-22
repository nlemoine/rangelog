<?php

declare(strict_types=1);

namespace n5s\Rangelog\Exception;

use Throwable;

/**
 * Thrown by FetcherInterface implementations when an HTTP/network
 * call fails for any reason other than a 429 (which throws
 * RateLimitedException — a sibling, NOT a child of this class).
 *
 * Wraps the underlying PSR-18 ClientExceptionInterface as $previous.
 *
 * Carries two readonly fields:
 *   - $statusCode: HTTP status (3xx except 304, 4xx, 5xx); 0 for
 *     non-HTTP failures (network, malformed request, generic
 *     PSR-18 ClientException).
 *   - $bodyExcerpt: first 1024 bytes of the response body, UTF-8-safe
 *     (use mb_strcut at the call site to avoid mid-multibyte
 *     truncation). Empty string when no body available.
 *
 * Constructor signature preserves the standard PHP exception positional
 * order (message, code, previous) and appends statusCode / bodyExcerpt
 * — same shape as RateLimitedException. Named-arg call sites are
 * recommended.
 *
 * SECURITY: $bodyExcerpt is a slice of the upstream response body
 * (caller-controllable through the URL but not through auth headers,
 * which the library never reads). Callers should not log or render
 * $bodyExcerpt to untrusted UIs without their own sanitisation.
 */
final class FetchException extends ChangelogException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly int $statusCode = 0,
        public readonly string $bodyExcerpt = '',
    ) {
        parent::__construct($message, $code, $previous);
    }
}
