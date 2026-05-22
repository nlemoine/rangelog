<?php

declare(strict_types=1);

namespace n5s\Rangelog\Tests\Unit\Fetcher;

use n5s\Rangelog\Domain\RawResponse;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Exception\FetchException;
use n5s\Rangelog\Exception\RateLimitedException;
use n5s\Rangelog\Fetcher\CachedResponse;
use n5s\Rangelog\Fetcher\CachingFetcher;
use n5s\Rangelog\Fetcher\FetcherInterface;
use n5s\Rangelog\Tests\TestSupport\ArrayLogger;
use n5s\Rangelog\Tests\TestSupport\RecordingCache;
use n5s\Rangelog\Tests\TestSupport\RecordingFetcher;
use n5s\Rangelog\Tests\TestSupport\ThrowingSetCache;
use Psr\Http\Client\ClientExceptionInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Tests for CachingFetcher decorator + CachedResponse VO.
 */


// ---------------------------------------------------------------------------
// Structure tests (3)
// ---------------------------------------------------------------------------

it('is a final class implementing FetcherInterface', function (): void {
    $reflection = new ReflectionClass(CachingFetcher::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->implementsInterface(FetcherInterface::class))->toBeTrue();
});

it('accepts FetcherInterface inner, CacheInterface cache, int defaultTtl, ?LoggerInterface logger as constructor args', function (): void {
    $inner = new RecordingFetcher();
    $cache = new RecordingCache();
    $logger = new ArrayLogger();

    // With explicit defaultTtl + logger
    $explicit = new CachingFetcher(inner: $inner, cache: $cache, defaultTtl: 7200, logger: $logger);
    expect($explicit)->toBeInstanceOf(CachingFetcher::class);

    // With defaults (defaultTtl default = 3600)
    $defaults = new CachingFetcher(inner: $inner, cache: $cache);
    expect($defaults)->toBeInstanceOf(CachingFetcher::class);
});

it('decorates inner: implements FetcherInterface (Liskov)', function (): void {
    $fetcher = new CachingFetcher(inner: new RecordingFetcher(), cache: new RecordingCache());
    $promoted = (static fn (FetcherInterface $f): FetcherInterface => $f)($fetcher);

    expect($promoted)->toBeInstanceOf(FetcherInterface::class);
});

// ---------------------------------------------------------------------------
// Cache key
// ---------------------------------------------------------------------------

it('computes cache key as sha256 hash of fetcher|type|url', function (): void {
    $cache = new RecordingCache();
    $inner = new RecordingFetcher(new RawResponse(
        'body',
        'text/plain',
        new Source('github_releases', 'https://api.github.com/repos/x/y/releases'),
    ));
    $fetcher = new CachingFetcher(inner: $inner, cache: $cache);
    $source = new Source(type: 'github_releases', url: 'https://api.github.com/repos/x/y/releases');

    $fetcher->fetch($source);

    $expected = hash('sha256', 'fetcher|github_releases|https://api.github.com/repos/x/y/releases');
    $cacheKeysSeen = array_column($cache->ops, 'key');
    expect($cacheKeysSeen)->toContain($expected);
    expect($expected)->toMatch('/\A[a-f0-9]{64}\z/');
});

it('cache key contains no PSR-16-reserved characters', function (): void {
    $cache = new RecordingCache();
    $inner = new RecordingFetcher();
    $fetcher = new CachingFetcher(inner: $inner, cache: $cache);
    $source = new Source(type: 'github_file', url: 'https://example.com/CHANGELOG.md');

    $fetcher->fetch($source);

    $cacheKeysSeen = array_column($cache->ops, 'key');
    expect($cacheKeysSeen)->not->toBeEmpty();
    foreach ($cacheKeysSeen as $key) {
        expect($key)->toMatch('/\A[a-f0-9]{64}\z/');
        // Reserved set: {}()/\@:
        expect($key)->not->toMatch('/[{}()\/\\\\@:]/');
    }
});

// ---------------------------------------------------------------------------
// Cache miss path
// ---------------------------------------------------------------------------

it('on cache miss: delegates to inner and stores the result', function (): void {
    $source = new Source('github_releases', 'https://example.com/r');
    $innerResponse = new RawResponse('inner body', 'text/plain', $source);
    $cache = new RecordingCache();
    $inner = new RecordingFetcher($innerResponse);
    $fetcher = new CachingFetcher(inner: $inner, cache: $cache);

    $result = $fetcher->fetch($source);

    expect($inner->callCount)->toBe(1);
    expect($inner->lastSource)->toBe($source);
    expect($result)->toBe($innerResponse);

    $setOps = array_filter($cache->ops, static fn (array $op): bool => $op['op'] === 'set');
    expect($setOps)->toHaveCount(1);
    $firstSet = reset($setOps);
    if ($firstSet === false) {
        return;
    }
    $expectedKey = hash('sha256', 'fetcher|github_releases|https://example.com/r');
    expect($firstSet['key'])->toBe($expectedKey);
    expect($firstSet['value'])->toBeInstanceOf(CachedResponse::class);
});

it('logs debug "Cache miss for {url}, falling through" before delegating', function (): void {
    $source = new Source('github_releases', 'https://example.com/r');
    $logger = new ArrayLogger();
    $fetcher = new CachingFetcher(
        inner: new RecordingFetcher(),
        cache: new RecordingCache(),
        logger: $logger,
    );

    $fetcher->fetch($source);

    $missRecords = array_filter(
        $logger->records,
        static fn (array $r): bool => $r['level'] === 'debug' && str_contains($r['message'], 'Cache miss'),
    );
    expect($missRecords)->not->toBeEmpty();

    $first = reset($missRecords);
    if ($first === false) {
        return;
    }
    expect($first['context'])->toHaveKey('url');
});

// ---------------------------------------------------------------------------
// Cache hit path no ETag
// ---------------------------------------------------------------------------

it('on cache hit with no stored etag: returns cached response without calling inner', function (): void {
    $source = new Source('github_releases', 'https://example.com/r');
    $cachedRaw = new RawResponse('cached body', 'text/plain', $source);
    $cache = new RecordingCache();
    $key = hash('sha256', 'fetcher|github_releases|https://example.com/r');
    $cache->store[$key] = new CachedResponse(response: $cachedRaw, etag: null);

    // Inner stub throws if called — guarantees we never delegate.
    $inner = new RecordingFetcher(throws: new RuntimeException('inner must not be called on no-etag hit'));
    $fetcher = new CachingFetcher(inner: $inner, cache: $cache);

    $result = $fetcher->fetch($source);

    expect($result)->toBe($cachedRaw);
    expect($inner->callCount)->toBe(0);
});

it('logs debug "Cache hit for {url}" on no-etag hit', function (): void {
    $source = new Source('github_releases', 'https://example.com/r');
    $cachedRaw = new RawResponse('cached body', 'text/plain', $source);
    $cache = new RecordingCache();
    $key = hash('sha256', 'fetcher|github_releases|https://example.com/r');
    $cache->store[$key] = new CachedResponse(response: $cachedRaw, etag: null);

    $logger = new ArrayLogger();
    $fetcher = new CachingFetcher(
        inner: new RecordingFetcher(),
        cache: $cache,
        logger: $logger,
    );

    $fetcher->fetch($source);

    $hits = array_filter(
        $logger->records,
        static fn (array $r): bool => $r['level'] === 'debug' && str_contains($r['message'], 'Cache hit'),
    );
    expect($hits)->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// Cache hit path WITH ETag — 304 round-trip
// ---------------------------------------------------------------------------

it('on cache hit with stored etag: builds new Source with _if_none_match metadata before calling inner', function (): void {
    $source = new Source(
        type: 'github_file',
        url: 'https://example.com/CHANGELOG.md',
        metadata: ['_user_supplied' => 'preserved'],
    );
    $cachedRaw = new RawResponse('cached body', 'text/markdown', $source);
    $cache = new RecordingCache();
    $key = hash('sha256', 'fetcher|github_file|https://example.com/CHANGELOG.md');
    $cache->store[$key] = new CachedResponse(response: $cachedRaw, etag: '"v42"');

    // Inner returns 200 with a new body — we just inspect the Source it received.
    $innerResponse = new RawResponse(
        body: 'fresh body',
        contentType: 'text/markdown',
        source: new Source('github_file', 'https://example.com/CHANGELOG.md', ['_response_etag' => '"new"']),
    );
    $inner = new RecordingFetcher($innerResponse);
    $fetcher = new CachingFetcher(inner: $inner, cache: $cache);

    $fetcher->fetch($source);

    expect($inner->callCount)->toBe(1);
    expect($inner->lastSource)->not->toBeNull();
    /** @var Source $received */
    $received = $inner->lastSource;
    expect($received->metadata['_if_none_match'] ?? null)->toBe('"v42"');
    // Original metadata preserved.
    expect($received->metadata['_user_supplied'] ?? null)->toBe('preserved');
    expect($received->type)->toBe('github_file');
    expect($received->url)->toBe('https://example.com/CHANGELOG.md');
});

it('on inner throwing FetchException(statusCode=304) AND cache had a stored entry: refreshes TTL and returns cached response', function (): void {
    $source = new Source('github_file', 'https://example.com/CHANGELOG.md');
    $cachedRaw = new RawResponse('cached body', 'text/markdown', $source);
    $cache = new RecordingCache();
    $key = hash('sha256', 'fetcher|github_file|https://example.com/CHANGELOG.md');
    $cache->store[$key] = new CachedResponse(response: $cachedRaw, etag: '"v42"');

    $inner = new RecordingFetcher(throws: new FetchException(statusCode: 304));
    $fetcher = new CachingFetcher(inner: $inner, cache: $cache, defaultTtl: 7200);

    $response = $fetcher->fetch($source);

    expect($response->body)->toBe('cached body');
    expect($inner->lastSource)->not->toBeNull();
    /** @var Source $received */
    $received = $inner->lastSource;
    expect($received->metadata['_if_none_match'] ?? null)->toBe('"v42"');

    // TTL refresh: one set op for this key, with the cached value and defaultTtl=7200.
    $setOpsForKey = array_filter(
        $cache->ops,
        static fn (array $op): bool => $op['op'] === 'set' && $op['key'] === $key,
    );
    expect($setOpsForKey)->toHaveCount(1);
    $refresh = reset($setOpsForKey);
    if ($refresh === false) {
        return;
    }
    expect($refresh['ttl'])->toBe(7200);
    expect($refresh['value'])->toBeInstanceOf(CachedResponse::class);
});

it('logs info "304 Not Modified for {url}, serving from cache" on 304 path', function (): void {
    $source = new Source('github_file', 'https://example.com/CHANGELOG.md');
    $cachedRaw = new RawResponse('cached body', 'text/markdown', $source);
    $cache = new RecordingCache();
    $key = hash('sha256', 'fetcher|github_file|https://example.com/CHANGELOG.md');
    $cache->store[$key] = new CachedResponse(response: $cachedRaw, etag: '"v42"');

    $logger = new ArrayLogger();
    $fetcher = new CachingFetcher(
        inner: new RecordingFetcher(throws: new FetchException(statusCode: 304)),
        cache: $cache,
        logger: $logger,
    );

    $fetcher->fetch($source);

    $infos = array_filter(
        $logger->records,
        static fn (array $r): bool => $r['level'] === 'info' && str_contains($r['message'], '304 Not Modified'),
    );
    expect($infos)->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// Cache miss + ETag capture
// ---------------------------------------------------------------------------

it('on cache miss + 200 response with ETag in source metadata: stores CachedResponse with the captured etag', function (): void {
    $source = new Source('github_releases', 'https://example.com/r');
    $innerSourceWithEtag = new Source('github_releases', 'https://example.com/r', ['_response_etag' => '"abc"']);
    $innerResponse = new RawResponse('inner body', 'application/json', $innerSourceWithEtag);

    $cache = new RecordingCache();
    $inner = new RecordingFetcher($innerResponse);
    $fetcher = new CachingFetcher(inner: $inner, cache: $cache);

    $fetcher->fetch($source);

    $setOps = array_filter($cache->ops, static fn (array $op): bool => $op['op'] === 'set');
    expect($setOps)->toHaveCount(1);
    $stored = reset($setOps);
    if ($stored === false) {
        return;
    }
    $value = $stored['value'];
    expect($value)->toBeInstanceOf(CachedResponse::class);
    if (! $value instanceof CachedResponse) {
        return;
    }
    expect($value->etag)->toBe('"abc"');
});

// ---------------------------------------------------------------------------
// Negative-result rule
// ---------------------------------------------------------------------------

it('does NOT cache 5xx responses (FetchException with statusCode=500 propagates)', function (): void {
    $source = new Source('github_releases', 'https://example.com/r');
    $cache = new RecordingCache();
    $inner = new RecordingFetcher(throws: new FetchException(statusCode: 500));
    $fetcher = new CachingFetcher(inner: $inner, cache: $cache);

    $thrown = null;
    try {
        $fetcher->fetch($source);
    } catch (FetchException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(FetchException::class);
    if (! $thrown instanceof FetchException) {
        return;
    }
    expect($thrown->statusCode)->toBe(500);
    expect($inner->callCount)->toBe(1);

    $setOps = array_filter($cache->ops, static fn (array $op): bool => $op['op'] === 'set');
    expect($setOps)->toBeEmpty();
});

it('does NOT cache 429 responses (RateLimitedException propagates unchanged)', function (): void {
    $source = new Source('github_releases', 'https://example.com/r');
    $cache = new RecordingCache();
    $inner = new RecordingFetcher(throws: new RateLimitedException('rate limited', retryAfter: 60));
    $fetcher = new CachingFetcher(inner: $inner, cache: $cache);

    $thrown = null;
    try {
        $fetcher->fetch($source);
    } catch (RateLimitedException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RateLimitedException::class);

    $setOps = array_filter($cache->ops, static fn (array $op): bool => $op['op'] === 'set');
    expect($setOps)->toBeEmpty();
});

it('does NOT cache PSR-18 ClientException-wrapped FetchException (statusCode=0)', function (): void {
    $source = new Source('github_releases', 'https://example.com/r');
    $cache = new RecordingCache();

    $clientException = new class () extends RuntimeException implements ClientExceptionInterface {};
    $inner = new RecordingFetcher(throws: new FetchException(
        message: 'network down',
        previous: $clientException,
        statusCode: 0,
    ));
    $fetcher = new CachingFetcher(inner: $inner, cache: $cache);

    $thrown = null;
    try {
        $fetcher->fetch($source);
    } catch (FetchException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(FetchException::class);
    if (! $thrown instanceof FetchException) {
        return;
    }
    expect($thrown->statusCode)->toBe(0);

    $setOps = array_filter($cache->ops, static fn (array $op): bool => $op['op'] === 'set');
    expect($setOps)->toBeEmpty();
});

// ---------------------------------------------------------------------------
// 304 with no cache entry — contract violation
// ---------------------------------------------------------------------------

it('on inner throwing FetchException(statusCode=304) BUT no cached entry exists: rethrows FetchException unchanged', function (): void {
    $source = new Source('github_file', 'https://example.com/CHANGELOG.md');
    $cache = new RecordingCache();
    // No pre-populated CachedResponse — cache miss path will be taken.
    $inner = new RecordingFetcher(throws: new FetchException(statusCode: 304));
    $fetcher = new CachingFetcher(inner: $inner, cache: $cache);

    $thrown = null;
    try {
        $fetcher->fetch($source);
    } catch (FetchException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(FetchException::class);
    if (! $thrown instanceof FetchException) {
        return;
    }
    expect($thrown->statusCode)->toBe(304);

    $setOps = array_filter($cache->ops, static fn (array $op): bool => $op['op'] === 'set');
    expect($setOps)->toBeEmpty();
});

// ---------------------------------------------------------------------------
// Best-effort cache writes
// ---------------------------------------------------------------------------

it('logs warning when cache->set() throws and does NOT propagate the cache-write exception', function (): void {
    $source = new Source('github_releases', 'https://example.com/r');
    $innerResponse = new RawResponse('inner body', 'text/plain', $source);
    $logger = new ArrayLogger();
    $fetcher = new CachingFetcher(
        inner: new RecordingFetcher($innerResponse),
        cache: new ThrowingSetCache(),
        logger: $logger,
    );

    $result = $fetcher->fetch($source);

    // The caller still sees the inner's response, despite the cache write blowing up.
    expect($result)->toBe($innerResponse);

    $warnings = array_filter(
        $logger->records,
        static fn (array $r): bool => $r['level'] === 'warning' && str_contains($r['message'], 'Cache write failed'),
    );
    expect($warnings)->not->toBeEmpty();
    $first = reset($warnings);
    if ($first === false) {
        return;
    }
    expect($first['context'])->toHaveKey('url');
    expect($first['context'])->toHaveKey('message');
});
