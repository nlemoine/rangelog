<?php

declare(strict_types=1);

namespace n5s\Rangelog\Fetcher;

use n5s\Rangelog\Domain\RawResponse;
use n5s\Rangelog\Domain\Source;

/**
 * Composes a base FetcherInterface with an ordered list of decorator
 * factories, producing a single FetcherInterface (Composite + Decorator).
 *
 * Lets callers wire CachingFetcher, RetryingFetcher, LoggingFetcher,
 * etc. without nesting `new A(new B(new C(...)))`.
 *
 * Decorators are factory closures: each closure receives the inner
 * FetcherInterface and returns a wrapped FetcherInterface. The stack
 * walks the array LEFT-TO-RIGHT applying each factory; the LAST factory
 * in the array becomes the OUTERMOST wrapper. This matches the
 * intuition "decorators applied in order; the last one runs first".
 *
 * Example:
 *
 *   $fetcher = new FetcherStack(
 *       base: new HttpFetcher($psrClient, $requestFactory),
 *       decorators: [
 *           fn ($inner) => new CachingFetcher($inner, $cache, ttl: 3600),
 *           fn ($inner) => new RetryingFetcher($inner, attempts: 3),
 *           fn ($inner) => new LoggingFetcher($inner, $logger),
 *       ],
 *   );
 *   // Effective wiring: Logging(Retrying(Caching(Http)))
 *
 * Empty-decorators case (just a base) is supported.
 */
final readonly class FetcherStack implements FetcherInterface
{
    private FetcherInterface $composed;

    /**
     * @param array<int, callable(FetcherInterface): FetcherInterface> $decorators
     *        Factory closures applied in array order; last is outermost.
     */
    public function __construct(
        FetcherInterface $base,
        array $decorators = [],
    ) {
        $composed = $base;
        foreach ($decorators as $factory) {
            $composed = $factory($composed);
        }

        $this->composed = $composed;
    }

    public function fetch(Source $source): RawResponse
    {
        return $this->composed->fetch($source);
    }
}
