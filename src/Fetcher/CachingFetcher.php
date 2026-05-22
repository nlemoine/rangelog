<?php

declare(strict_types=1);

namespace n5s\Rangelog\Fetcher;

use n5s\Rangelog\Domain\RawResponse;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Exception\FetchException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * PSR-16 caching decorator for {@see FetcherInterface}.
 *
 * Behaviour:
 *  - Cache miss: delegate to the inner fetcher and store the response
 *    under sha256("fetcher|{type}|{url}") with `defaultTtl`.
 *  - Cache hit, no stored ETag: serve from cache, skip inner.
 *  - Cache hit, stored ETag: inject `_if_none_match` into the Source
 *    metadata and delegate; on inner throwing FetchException(304),
 *    refresh the entry's TTL and return the cached response.
 *    Any non-304 FetchException propagates.
 *  - 5xx, 429, network errors are never cached.
 *  - Cache write failures are logged at `warning` and swallowed —
 *    callers must still get a response when the cache backend is
 *    misbehaving.
 */
final readonly class CachingFetcher implements FetcherInterface
{
    private LoggerInterface $logger;

    public function __construct(
        private FetcherInterface $inner,
        private CacheInterface $cache,
        private int $defaultTtl = 3600,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function fetch(Source $source): RawResponse
    {
        $key = $this->cacheKey($source);
        $cached = $this->loadFromCache($key);

        if (!$cached instanceof CachedResponse) {
            $this->logger->debug('Cache miss for {url}, falling through', ['url' => $source->url]);
            $response = $this->inner->fetch($source);
            $this->store($key, $response, $source->url);

            return $response;
        }

        if ($cached->etag === null) {
            $this->logger->debug('Cache hit for {url}', ['url' => $source->url]);

            return $cached->response;
        }

        $conditional = new Source(
            type: $source->type,
            url: $source->url,
            metadata: $source->metadata + ['_if_none_match' => $cached->etag],
        );

        try {
            $response = $this->inner->fetch($conditional);
        } catch (FetchException $e) {
            if ($e->statusCode === 304) {
                $this->logger->info('304 Not Modified for {url}, serving from cache', ['url' => $source->url]);
                $this->refreshTtl($key, $cached);

                return $cached->response;
            }

            throw $e;
        }

        $this->store($key, $response, $source->url);

        return $response;
    }

    private function cacheKey(Source $source): string
    {
        return hash('sha256', 'fetcher|' . $source->type . '|' . $source->url);
    }

    private function loadFromCache(string $key): ?CachedResponse
    {
        try {
            $value = $this->cache->get($key);
        } catch (Throwable) {
            return null;
        }

        return $value instanceof CachedResponse ? $value : null;
    }

    private function store(string $key, RawResponse $response, string $url): void
    {
        $etag = $response->source->metadata['_response_etag'] ?? null;
        $cached = new CachedResponse(
            response: $response,
            etag: \is_string($etag) ? $etag : null,
        );

        try {
            $this->cache->set($key, $cached, $this->defaultTtl);
        } catch (Throwable $e) {
            $this->logger->warning('Cache write failed for {url}: {message}', [
                'url' => $url,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function refreshTtl(string $key, CachedResponse $cached): void
    {
        try {
            $this->cache->set($key, $cached, $this->defaultTtl);
        } catch (Throwable) {
            // Best-effort — TTL refresh is non-essential.
        }
    }
}
