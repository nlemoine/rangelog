<?php

declare(strict_types=1);

namespace n5s\Rangelog\Exception;

use DateTimeImmutable;
use Throwable;

/**
 * Thrown when an upstream service responds 429 Too Many Requests.
 *
 * This is a TOP-LEVEL sibling under ChangelogException — NOT a child of
 * FetchException. Callers handle it with a dedicated catch block before the
 * generic FetchException catch:
 *
 *   catch (RateLimitedException $e) { sleep($e->retryAfter ?? 60); }
 *   catch (FetchException $e)        { ... }
 *
 * Properties are populated by fetcher logic from the response headers
 * Retry-After (seconds) and X-RateLimit-Reset (epoch).
 *
 * Constructor signature preserves the standard PHP exception positional
 * order (message, code, previous) and appends retryAfter / rateLimitReset.
 * Named-argument call sites are the recommended usage.
 */
final class RateLimitedException extends ChangelogException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?int $retryAfter = null,
        public readonly ?DateTimeImmutable $rateLimitReset = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
