<?php

declare(strict_types=1);

namespace n5s\Rangelog\Tests\Unit\Fetcher;

use DateTimeImmutable;
use Http\Mock\Client as MockClient;
use n5s\Rangelog\Cache\Psr6CacheAdapter;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Exception\FetchException;
use n5s\Rangelog\Exception\RateLimitedException;
use n5s\Rangelog\Fetcher\CachingFetcher;
use n5s\Rangelog\Fetcher\FetcherInterface;
use n5s\Rangelog\Fetcher\FetcherStack;
use n5s\Rangelog\Fetcher\HttpFetcher;
use n5s\Rangelog\Tests\TestSupport\ArrayCachePool;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;

/**
 * Wires the full Fetcher stack end-to-end (HttpFetcher + CachingFetcher
 * composed via FetcherStack against php-http/mock-client + nyholm/psr7 +
 * Psr6CacheAdapter(ArrayCachePool)). No live HTTP traffic — mock-client only.
 */

/**
 * @return array{
 *   stack: FetcherInterface,
 *   mockClient: MockClient,
 *   cache: Psr6CacheAdapter,
 *   pool: ArrayCachePool,
 * }
 */
function buildIntegrationStack(): array
{
    $mockClient = new MockClient();
    $factory = new Psr17Factory();
    $pool = new ArrayCachePool();
    $cache = new Psr6CacheAdapter($pool);

    $stack = new FetcherStack(
        base: new HttpFetcher($mockClient, $factory),
        decorators: [
            fn (FetcherInterface $inner): FetcherInterface => new CachingFetcher(
                inner: $inner,
                cache: $cache,
                defaultTtl: 3600,
            ),
        ],
    );

    return ['stack' => $stack, 'mockClient' => $mockClient, 'cache' => $cache, 'pool' => $pool];
}

it('caches a 200 response and returns it on second call without re-hitting HTTP', function (): void {
    $env = buildIntegrationStack();
    $env['mockClient']->addResponse(new Response(200, ['Content-Type' => 'text/markdown'], "## 1.2.3\n- Fixed\n"));

    $source = new Source(type: 'github_file', url: 'https://example.com/CHANGELOG.md');

    // First call — hits HTTP, stores in cache
    $first = $env['stack']->fetch($source);
    expect($first->body)->toBe("## 1.2.3\n- Fixed\n");
    expect($first->contentType)->toBe('text/markdown');

    // Second call — must NOT issue a second HTTP request (no more responses queued)
    $second = $env['stack']->fetch($source);
    expect($second->body)->toBe("## 1.2.3\n- Fixed\n");

    // Mock client should have received exactly ONE request
    expect($env['mockClient']->getRequests())->toHaveCount(1);
});

it('round-trips ETag via If-None-Match and serves cached body on 304', function (): void {
    $env = buildIntegrationStack();
    // First response: 200 + ETag
    $env['mockClient']->addResponse(new Response(200, ['ETag' => '"v42"', 'Content-Type' => 'text/markdown'], 'v42 body'));
    // Second response: 304 (stale validator)
    $env['mockClient']->addResponse(new Response(304, [], ''));

    $source = new Source(type: 'github_file', url: 'https://example.com/CHANGELOG.md');

    $first = $env['stack']->fetch($source);
    expect($first->body)->toBe('v42 body');

    $second = $env['stack']->fetch($source);
    // Cached body returned even though the second HTTP response was 304
    expect($second->body)->toBe('v42 body');

    // Both calls hit HTTP (because the second one sent a conditional GET)
    $requests = $env['mockClient']->getRequests();
    expect($requests)->toHaveCount(2);

    // First request had no If-None-Match
    expect($requests[0]->getHeaderLine('If-None-Match'))->toBe('');
    // Second request DID have If-None-Match: "v42"
    expect($requests[1]->getHeaderLine('If-None-Match'))->toBe('"v42"');
});

it('does not cache a 5xx response and re-issues HTTP on subsequent call', function (): void {
    $env = buildIntegrationStack();
    $env['mockClient']->addResponse(new Response(500, [], 'internal error'));
    $env['mockClient']->addResponse(new Response(200, [], 'recovered body'));

    $source = new Source(type: 'github_file', url: 'https://example.com/CHANGELOG.md');

    // First call — 500 → FetchException
    $caught = null;
    try {
        $env['stack']->fetch($source);
    } catch (FetchException $e) {
        $caught = $e;
    }
    expect($caught)->toBeInstanceOf(FetchException::class);
    if (! $caught instanceof FetchException) {
        return;
    }
    expect($caught->statusCode)->toBe(500);

    // Second call — must hit HTTP again (cache should be empty)
    $second = $env['stack']->fetch($source);
    expect($second->body)->toBe('recovered body');

    // Confirm two HTTP requests were issued (cache was untouched on the 500)
    expect($env['mockClient']->getRequests())->toHaveCount(2);
});

it('propagates 429 as RateLimitedException with parsed retryAfter and rateLimitReset', function (): void {
    $env = buildIntegrationStack();
    $env['mockClient']->addResponse(new Response(429, [
        'Retry-After' => '60',
        'X-RateLimit-Reset' => '1731234567',
    ], '{}'));

    $source = new Source(type: 'github_releases', url: 'https://api.github.com/repos/x/y/releases');

    $caught = null;
    try {
        $env['stack']->fetch($source);
    } catch (RateLimitedException $e) {
        $caught = $e;
    }
    expect($caught)->toBeInstanceOf(RateLimitedException::class);
    if (! $caught instanceof RateLimitedException) {
        return;
    }
    expect($caught->retryAfter)->toBe(60);
    expect($caught->rateLimitReset)->toBeInstanceOf(DateTimeImmutable::class);
    if (! $caught->rateLimitReset instanceof DateTimeImmutable) {
        return;
    }
    expect($caught->rateLimitReset->getTimestamp())->toBe(1731234567);

    // No cache write should have occurred — a subsequent fetch must hit HTTP again
    $env['mockClient']->addResponse(new Response(200, [], 'next attempt'));
    $second = $env['stack']->fetch($source);
    expect($second->body)->toBe('next attempt');
    expect($env['mockClient']->getRequests())->toHaveCount(2);
});

it('FetcherStack composition: CachingFetcher (last decorator) is the outermost wrapper of HttpFetcher', function (): void {
    $env = buildIntegrationStack();
    // Without queuing a second response, the second fetch() of the same source
    // should return cached — proves CachingFetcher intercepts BEFORE HttpFetcher.
    $env['mockClient']->addResponse(new Response(200, [], 'A'));

    $source = new Source('t', 'https://example.com/x');
    $env['stack']->fetch($source);    // hits HTTP, caches
    $env['stack']->fetch($source);    // CachingFetcher intercepts BEFORE HttpFetcher

    expect($env['mockClient']->getRequests())->toHaveCount(1);
    // If composition were inverted (HttpFetcher outside CachingFetcher), the second fetch()
    // would have failed because no second response was queued.
});
