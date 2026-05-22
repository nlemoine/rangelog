<?php

declare(strict_types=1);

namespace n5s\Rangelog\Domain;

/**
 * Buffered HTTP response from a Fetcher.
 *
 * The body is captured as a string (not a PSR-7 StreamInterface). The fetcher
 * MUST read the stream to completion once and pass the resulting string here
 * — this avoids the read-once cursor trap entirely.
 */
final readonly class RawResponse
{
    public function __construct(
        public string $body,
        public string $contentType,
        public Source $source,
    ) {
    }
}
