<?php

declare(strict_types=1);

namespace n5s\Rangelog\Tests\TestSupport;

use n5s\Rangelog\Domain\RawResponse;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Fetcher\FetcherInterface;
use Throwable;

/**
 * Stub {@see FetcherInterface} that captures the Source it received and
 * either returns a fixed RawResponse or throws a configured Throwable.
 *
 * Used by CachingFetcher tests to assert call counts and the exact
 * Source instances the decorator passes to its inner fetcher.
 */
final class RecordingFetcher implements FetcherInterface
{
    public ?Source $lastSource = null;

    public int $callCount = 0;

    public function __construct(
        private readonly ?RawResponse $response = null,
        private readonly ?Throwable $throws = null,
    ) {
    }

    public function fetch(Source $source): RawResponse
    {
        ++$this->callCount;
        $this->lastSource = $source;

        if ($this->throws instanceof Throwable) {
            throw $this->throws;
        }

        return $this->response ?? new RawResponse('default body', 'text/plain', $source);
    }
}
