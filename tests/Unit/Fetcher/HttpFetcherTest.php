<?php

declare(strict_types=1);

use Http\Client\Exception\TransferException;
use Http\Mock\Client as MockClient;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Domain\SourceTypes;
use n5s\Rangelog\Exception\FetchException;
use n5s\Rangelog\Exception\RateLimitedException;
use n5s\Rangelog\Fetcher\AuthorizingFetcher;
use n5s\Rangelog\Fetcher\FetcherInterface;
use n5s\Rangelog\Fetcher\HttpFetcher;
use n5s\Rangelog\Tests\TestSupport\ArrayLogger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Resolve the last request the MockClient saw, narrowing to RequestInterface so
 * the assertion sites stay PHPStan-clean (getLastRequest() returns
 * RequestInterface|false in php-http/mock-client).
 */
function lastSentRequest(MockClient $client): RequestInterface
{
    $sent = $client->getLastRequest();
    if (! $sent instanceof RequestInterface) {
        throw new LogicException('MockClient has no recorded request');
    }

    return $sent;
}

// ---------------------------------------------------------------------------
// Structure
// ---------------------------------------------------------------------------

it('is a final class implementing FetcherInterface', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $factory = new Psr17Factory();

    $reflection = new ReflectionClass(HttpFetcher::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->implementsInterface(FetcherInterface::class))->toBeTrue();
});

it('accepts ClientInterface and RequestFactoryInterface as the first two constructor arguments', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $factory = new Psr17Factory();

    // Type-checker enforces the contract; construction without TypeError proves it.
    $fetcher = new HttpFetcher($mockClient, $factory);

    expect($fetcher)->toBeInstanceOf(HttpFetcher::class);
});

it('accepts userAgent and logger as optional constructor arguments', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $factory = new Psr17Factory();

    $logger = new ArrayLogger();
    $fetcher = new HttpFetcher($mockClient, $factory, 'custom-ua/1.0', $logger);

    expect($fetcher)->toBeInstanceOf(HttpFetcher::class);
});

it('can be constructed with no arguments via php-http/discovery fallback', function (): void {
    // Zero-arg construction MUST succeed by discovering the installed PSR-18 client
    // and PSR-17 request factory from vendor/ (php-http/discovery in dev: nyholm/psr7
    // for the factory, php-http/mock-client for the client). BYO injection paths
    // (every other test in this file) are unaffected.
    $fetcher = new HttpFetcher();

    expect($fetcher)->toBeInstanceOf(HttpFetcher::class);

    // Confirm the discovery fallback actually populated the two PSR holes with
    // concrete instances of the expected interfaces. Use ReflectionClass (same
    // pattern as the isFinal() check at line 42) to read the private properties.
    $reflection = new ReflectionClass(HttpFetcher::class);

    $httpClientProperty = $reflection->getProperty('httpClient');
    $resolvedHttpClient = $httpClientProperty->getValue($fetcher);
    expect($resolvedHttpClient)->toBeInstanceOf(ClientInterface::class);

    $requestFactoryProperty = $reflection->getProperty('requestFactory');
    $resolvedRequestFactory = $requestFactoryProperty->getValue($fetcher);
    expect($resolvedRequestFactory)->toBeInstanceOf(RequestFactoryInterface::class);
});

// ---------------------------------------------------------------------------
// Body buffering
// ---------------------------------------------------------------------------

it('buffers PSR-7 stream body to string and returns RawResponse', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $factory = new Psr17Factory();

    $mockClient->addResponse(
        new Response(200, ['Content-Type' => 'text/markdown'], "## 1.2.3\n- Fixed\n"),
    );

    $fetcher = new HttpFetcher($mockClient, $factory);
    $source = new Source(type: 'github_file', url: 'https://example.com/CHANGELOG.md');

    $response = $fetcher->fetch($source);

    expect($response->body)->toBe("## 1.2.3\n- Fixed\n");
    expect($response->contentType)->toBe('text/markdown');
});

// ---------------------------------------------------------------------------
// Outbound headers
// ---------------------------------------------------------------------------

it('always sets User-Agent header on outbound request', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $factory = new Psr17Factory();

    $mockClient->addResponse(new Response(200, [], 'ok'));

    (new HttpFetcher($mockClient, $factory))
        ->fetch(new Source(type: 'github_file', url: 'https://example.com/'));

    $sent = lastSentRequest($mockClient);
    expect($sent->getHeaderLine('User-Agent'))
        ->toBe('n5s/rangelog/0.x (+https://github.com/n5s/rangelog)');
});

it('uses caller-supplied userAgent when provided', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $factory = new Psr17Factory();

    $mockClient->addResponse(new Response(200, [], 'ok'));

    (new HttpFetcher($mockClient, $factory, 'my-app/1.0'))
        ->fetch(new Source(type: 'github_file', url: 'https://example.com/'));

    expect(lastSentRequest($mockClient)->getHeaderLine('User-Agent'))->toBe('my-app/1.0');
});

it('does not set Accept-Encoding, Accept, or Authorization headers', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $factory = new Psr17Factory();

    $mockClient->addResponse(new Response(200, [], 'ok'));

    (new HttpFetcher($mockClient, $factory))
        ->fetch(new Source(type: 'github_file', url: 'https://example.com/'));

    $sent = lastSentRequest($mockClient);
    expect($sent->getHeaderLine('Accept-Encoding'))->toBe('');
    expect($sent->getHeaderLine('Accept'))->toBe('');
    expect($sent->getHeaderLine('Authorization'))->toBe('');
});

// ---------------------------------------------------------------------------
// HTTP method
// ---------------------------------------------------------------------------

it('uses HTTP method GET', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $factory = new Psr17Factory();

    $mockClient->addResponse(new Response(200, [], 'ok'));

    (new HttpFetcher($mockClient, $factory))
        ->fetch(new Source(type: 'github_file', url: 'https://example.com/'));

    expect(lastSentRequest($mockClient)->getMethod())->toBe('GET');
});

// ---------------------------------------------------------------------------
// Conditional GET injection
// ---------------------------------------------------------------------------

it('forwards Source metadata _if_none_match into the If-None-Match request header', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $factory = new Psr17Factory();

    $mockClient->addResponse(new Response(200, [], 'ok'));

    $source = new Source(
        type: 'github_file',
        url: 'https://example.com/x',
        metadata: ['_if_none_match' => '"abc123"'],
    );

    (new HttpFetcher($mockClient, $factory))->fetch($source);

    expect(lastSentRequest($mockClient)->getHeaderLine('If-None-Match'))->toBe('"abc123"');
});

it('does NOT set If-None-Match when Source metadata _if_none_match is absent or empty', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $factory = new Psr17Factory();

    $mockClient->addResponse(new Response(200, [], 'ok'));
    $mockClient->addResponse(new Response(200, [], 'ok'));

    $fetcher = new HttpFetcher($mockClient, $factory);

    // Case A: metadata absent
    $fetcher->fetch(new Source(type: 'gh', url: 'https://example.com/a'));
    expect(lastSentRequest($mockClient)->getHeaderLine('If-None-Match'))->toBe('');

    // Case B: metadata key present but empty string
    $fetcher->fetch(new Source(
        type: 'gh',
        url: 'https://example.com/b',
        metadata: ['_if_none_match' => ''],
    ));
    expect(lastSentRequest($mockClient)->getHeaderLine('If-None-Match'))->toBe('');
});

// ---------------------------------------------------------------------------
// ETag capture
// ---------------------------------------------------------------------------

it('captures response ETag into RawResponse->source->metadata[_response_etag]', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $factory = new Psr17Factory();

    $mockClient->addResponse(new Response(200, ['ETag' => '"v42"'], 'body'));

    $originalSource = new Source(type: 'github_file', url: 'https://example.com/x');
    $fetcher = new HttpFetcher($mockClient, $factory);

    $response = $fetcher->fetch($originalSource);

    expect($response->source->metadata['_response_etag'] ?? null)->toBe('"v42"');
    // Source is final readonly — confirm a NEW instance is returned (not the original mutated).
    expect($response->source)->not->toBe($originalSource);
    // Preserved fields stay equal:
    expect($response->source->type)->toBe($originalSource->type);
    expect($response->source->url)->toBe($originalSource->url);
});

it('captures null _response_etag when no ETag header is present on the success response', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $factory = new Psr17Factory();

    $mockClient->addResponse(new Response(200, [], 'body'));

    $response = (new HttpFetcher($mockClient, $factory))
        ->fetch(new Source(type: 'gh', url: 'https://example.com/'));

    expect($response->source->metadata)->toHaveKey('_response_etag');
    expect($response->source->metadata['_response_etag'])->toBeNull();
});

// ---------------------------------------------------------------------------
// Status mapping — error paths
// ---------------------------------------------------------------------------

it('throws FetchException(statusCode=500) on 5xx with bodyExcerpt limited to 1024 UTF-8 bytes', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $factory = new Psr17Factory();

    $body = 'a' . str_repeat('x', 2000);
    $mockClient->addResponse(new Response(500, [], $body));

    $caught = null;
    try {
        (new HttpFetcher($mockClient, $factory))
            ->fetch(new Source(type: 'gh', url: 'https://example.com/'));
    } catch (FetchException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(FetchException::class);
    if (! $caught instanceof FetchException) {
        return;
    }
    expect($caught->statusCode)->toBe(500);
    expect(strlen($caught->bodyExcerpt))->toBeLessThanOrEqual(1024);
    expect(mb_check_encoding($caught->bodyExcerpt, 'UTF-8'))->toBeTrue();
});

it('throws FetchException(statusCode=304) on 304 (only meaningful inside CachingFetcher)', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $factory = new Psr17Factory();

    $mockClient->addResponse(new Response(304, [], ''));

    $caught = null;
    try {
        (new HttpFetcher($mockClient, $factory))
            ->fetch(new Source(type: 'gh', url: 'https://example.com/'));
    } catch (FetchException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(FetchException::class);
    if (! $caught instanceof FetchException) {
        return;
    }
    expect($caught->statusCode)->toBe(304);
});

it('throws FetchException(statusCode=0) when PSR-18 client raises a ClientException', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $factory = new Psr17Factory();

    $upstream = new TransferException('connection refused');

    $mockClient->addException($upstream);

    $caught = null;
    try {
        (new HttpFetcher($mockClient, $factory))
            ->fetch(new Source(type: 'gh', url: 'https://example.com/'));
    } catch (FetchException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(FetchException::class);
    if (! $caught instanceof FetchException) {
        return;
    }
    expect($caught->statusCode)->toBe(0);
    expect($caught->getPrevious())->toBe($upstream);
});

// ---------------------------------------------------------------------------
// Rate-limit handling
// ---------------------------------------------------------------------------

it('throws RateLimitedException with retryAfter parsed from Retry-After delta-seconds', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $factory = new Psr17Factory();

    $mockClient->addResponse(new Response(429, ['Retry-After' => '60'], '{}'));

    $caught = null;
    try {
        (new HttpFetcher($mockClient, $factory))
            ->fetch(new Source(type: 'gh', url: 'https://api.github.com/'));
    } catch (RateLimitedException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RateLimitedException::class);
    if (! $caught instanceof RateLimitedException) {
        return;
    }
    expect($caught->retryAfter)->toBe(60);
    expect($caught->rateLimitReset)->toBeInstanceOf(DateTimeImmutable::class);
});

it('parses X-RateLimit-Reset epoch into rateLimitReset DateTimeImmutable', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $factory = new Psr17Factory();

    $mockClient->addResponse(new Response(429, ['X-RateLimit-Reset' => '1731234567'], ''));

    $caught = null;
    try {
        (new HttpFetcher($mockClient, $factory))
            ->fetch(new Source(type: 'gh', url: 'https://api.github.com/'));
    } catch (RateLimitedException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RateLimitedException::class);
    if (! $caught instanceof RateLimitedException) {
        return;
    }
    expect($caught->rateLimitReset)->toBeInstanceOf(DateTimeImmutable::class);
    expect($caught->rateLimitReset?->getTimestamp())->toBe(1731234567);
});

it('precedence: X-RateLimit-Reset wins over Retry-After-derived rateLimitReset', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $factory = new Psr17Factory();

    $mockClient->addResponse(new Response(
        429,
        ['Retry-After' => '60', 'X-RateLimit-Reset' => '1731234567'],
        '',
    ));

    $caught = null;
    try {
        (new HttpFetcher($mockClient, $factory))
            ->fetch(new Source(type: 'gh', url: 'https://api.github.com/'));
    } catch (RateLimitedException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RateLimitedException::class);
    if (! $caught instanceof RateLimitedException) {
        return;
    }
    expect($caught->retryAfter)->toBe(60);
    expect($caught->rateLimitReset?->getTimestamp())->toBe(1731234567);
});

it('parses Retry-After HTTP-date IMF-fixdate to delta-seconds', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $factory = new Psr17Factory();

    $httpDate = (new DateTimeImmutable())
        ->modify('+30 seconds')
        ->format(DateTimeInterface::RFC7231);

    $mockClient->addResponse(new Response(429, ['Retry-After' => $httpDate], ''));

    $caught = null;
    try {
        (new HttpFetcher($mockClient, $factory))
            ->fetch(new Source(type: 'gh', url: 'https://api.github.com/'));
    } catch (RateLimitedException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RateLimitedException::class);
    if (! $caught instanceof RateLimitedException) {
        return;
    }
    // Allow ±2 seconds for clock drift during test execution.
    expect($caught->retryAfter)->toBeGreaterThanOrEqual(28);
    expect($caught->retryAfter)->toBeLessThanOrEqual(32);
});

it('leaves retryAfter and rateLimitReset null when both headers are absent', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $factory = new Psr17Factory();

    $mockClient->addResponse(new Response(429, [], ''));

    $caught = null;
    try {
        (new HttpFetcher($mockClient, $factory))
            ->fetch(new Source(type: 'gh', url: 'https://api.github.com/'));
    } catch (RateLimitedException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RateLimitedException::class);
    if (! $caught instanceof RateLimitedException) {
        return;
    }
    expect($caught->retryAfter)->toBeNull();
    expect($caught->rateLimitReset)->toBeNull();
});

// ---------------------------------------------------------------------------
// PSR-3 logging contract
// ---------------------------------------------------------------------------

it('emits debug GET {url} BEFORE sending the request', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $factory = new Psr17Factory();

    $mockClient->addResponse(new Response(200, [], 'ok'));
    $logger = new ArrayLogger();

    (new HttpFetcher($mockClient, $factory, 'ua', $logger))
        ->fetch(new Source(type: 'gh', url: 'https://example.com/x'));

    expect($logger->records)->not->toBeEmpty();
    // The very first record MUST be the pre-send debug.
    expect($logger->records[0]['level'])->toBe('debug');
    expect($logger->records[0]['message'])->toContain('GET');
    expect($logger->records[0]['context'])->toHaveKey('url');
    expect($logger->records[0]['context']['url'])->toBe('https://example.com/x');
});

it('emits debug {status} {url} ({bytes} bytes, {ms}ms) on successful 2xx', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $factory = new Psr17Factory();

    $body = 'hello-world';
    $mockClient->addResponse(new Response(200, ['Content-Type' => 'text/plain'], $body));
    $logger = new ArrayLogger();

    (new HttpFetcher($mockClient, $factory, 'ua', $logger))
        ->fetch(new Source(type: 'gh', url: 'https://example.com/x'));

    $successRecords = array_values(array_filter(
        $logger->records,
        static fn (array $r): bool => $r['level'] === 'debug'
            && isset($r['context']['bytes'])
            && isset($r['context']['status']),
    ));

    expect($successRecords)->not->toBeEmpty();
    expect($successRecords[0]['context']['status'])->toBe(200);
    expect($successRecords[0]['context']['bytes'])->toBe(strlen($body));
    expect($successRecords[0]['context'])->toHaveKey('ms');
});

it('emits notice on 429 BEFORE throwing RateLimitedException', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $factory = new Psr17Factory();

    $mockClient->addResponse(new Response(429, ['Retry-After' => '42'], ''));
    $logger = new ArrayLogger();

    $caught = null;
    try {
        (new HttpFetcher($mockClient, $factory, 'ua', $logger))
            ->fetch(new Source(type: 'gh', url: 'https://example.com/x'));
    } catch (RateLimitedException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RateLimitedException::class);

    $noticeRecords = array_values(array_filter(
        $logger->records,
        static fn (array $r): bool => $r['level'] === 'notice',
    ));
    expect($noticeRecords)->not->toBeEmpty();
    expect($noticeRecords[0]['context'])->toHaveKey('retryAfter');
    expect($noticeRecords[0]['context']['retryAfter'])->toBe(42);
});

it('emits error on PSR-18 ClientException BEFORE throwing FetchException', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $factory = new Psr17Factory();

    $upstream = new TransferException('boom');

    $mockClient->addException($upstream);
    $logger = new ArrayLogger();

    $caught = null;
    try {
        (new HttpFetcher($mockClient, $factory, 'ua', $logger))
            ->fetch(new Source(type: 'gh', url: 'https://example.com/x'));
    } catch (FetchException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(FetchException::class);

    $errorRecords = array_values(array_filter(
        $logger->records,
        static fn (array $r): bool => $r['level'] === 'error',
    ));
    expect($errorRecords)->not->toBeEmpty();
    expect($errorRecords[0]['context'])->toHaveKey('class');
    expect($errorRecords[0]['context'])->toHaveKey('message');
    expect($errorRecords[0]['context']['message'])->toBe('boom');
});

// ---------------------------------------------------------------------------
// Logger leakage / security
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// prefetchedBody short-circuit
// ---------------------------------------------------------------------------

it('short-circuits fetch() when source->prefetchedBody is set', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    // No queued response — short-circuit must NOT call PSR-18 client
    $fetcher = new HttpFetcher($mockClient, new Psr17Factory());

    $source = new Source(
        type: SourceTypes::GITHUB_RELEASES,
        url: 'https://api.github.com/repos/symfony/console/releases',
        prefetchedBody: 'PREFETCHED',
    );
    $response = $fetcher->fetch($source);

    expect($response->body)->toBe('PREFETCHED');
    expect($response->contentType)->toBe('application/json');
    expect($response->source)->toBe($source);
    expect($mockClient->getRequests())->toBeEmpty();
});

it('falls through to PSR-18 when prefetchedBody is null', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $mockClient->addResponse(new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}'));
    $fetcher = new HttpFetcher($mockClient, new Psr17Factory());

    $source = new Source(
        type: SourceTypes::GITHUB_RELEASES,
        url: 'https://api.github.com/repos/symfony/console/releases',
    );
    $response = $fetcher->fetch($source);

    // Response came from the queued mock (prefetchedBody null → no short-circuit)
    expect($response->body)->toBe('{"ok":true}');
    expect($mockClient->getRequests())->not->toBeEmpty();
});

it('NEVER logs request headers, response headers, request body, or response body (security)', function (): void {
    $factory = new Psr17Factory();
    $logger = new ArrayLogger();
    $source = new Source(
        type: 'gh',
        url: 'https://example.com/x',
        metadata: ['authorization' => 'should-not-appear'],
    );

    $forbiddenKeys = [
        'headers',
        'requestheaders',
        'responseheaders',
        'body',
        'requestbody',
        'responsebody',
        'authorization',
        'cookie',
    ];

    // Exercise every code path so EVERY logger record gets scanned: success, 429, exception.
    // Each path uses a fresh MockClient because php-http/mock-client always throws queued
    // exceptions before serving queued responses, so they cannot be sequenced in one client.
    $successClient = new MockClient(new Psr17Factory());
    $successClient->addResponse(new Response(200, ['ETag' => '"v1"'], 'ok'));
    (new HttpFetcher($successClient, $factory, 'ua', $logger))->fetch($source);

    $rateLimitClient = new MockClient(new Psr17Factory());
    $rateLimitClient->addResponse(new Response(429, ['Retry-After' => '60', 'Authorization' => 'Bearer secret-token'], 'body-payload'));
    try {
        (new HttpFetcher($rateLimitClient, $factory, 'ua', $logger))->fetch($source);
    } catch (RateLimitedException) {
        // expected
    }

    $exceptionClient = new MockClient(new Psr17Factory());
    $exceptionClient->addException(new TransferException('boom'));
    try {
        (new HttpFetcher($exceptionClient, $factory, 'ua', $logger))->fetch($source);
    } catch (FetchException) {
        // expected
    }

    foreach ($logger->records as $record) {
        foreach ($record['context'] as $key => $value) {
            $lowerKey = strtolower((string) $key);
            expect($forbiddenKeys)->not->toContain($lowerKey);
            // String values also must not leak the bearer token.
            if (is_string($value)) {
                expect(stripos($value, 'Bearer secret-token'))->toBe(false);
                expect(stripos($value, 'body-payload'))->toBe(false);
            }
        }
    }
});

// ---------------------------------------------------------------------------
// Auth headers application
// ---------------------------------------------------------------------------

it('forwards Source metadata _auth_headers into request headers via withHeader', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $factory = new Psr17Factory();

    $mockClient->addResponse(new Response(200, [], 'ok'));

    $source = new Source(
        type: 'github_releases',
        url: 'https://api.github.com/repos/x/y/releases',
        metadata: [AuthorizingFetcher::AUTH_HEADERS_METADATA_KEY => ['Authorization' => 'Bearer t', 'X-Custom' => 'v']],
    );

    (new HttpFetcher($mockClient, $factory))->fetch($source);

    expect(lastSentRequest($mockClient)->getHeaderLine('Authorization'))->toBe('Bearer t');
    expect(lastSentRequest($mockClient)->getHeaderLine('X-Custom'))->toBe('v');
});

it('auth headers override library defaults on collision — User-Agent', function (): void {
    $mockClient = new MockClient(new Psr17Factory());
    $factory = new Psr17Factory();

    $mockClient->addResponse(new Response(200, [], 'ok'));

    $source = new Source(
        type: 'github_releases',
        url: 'https://api.github.com/repos/x/y/releases',
        metadata: [AuthorizingFetcher::AUTH_HEADERS_METADATA_KEY => ['User-Agent' => 'override-ua']],
    );

    (new HttpFetcher($mockClient, $factory))->fetch($source);

    // Auth header applied LAST → caller-supplied User-Agent wins on collision.
    expect(lastSentRequest($mockClient)->getHeaderLine('User-Agent'))->toBe('override-ua');
});
