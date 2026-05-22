<?php

declare(strict_types=1);

use n5s\Rangelog\Domain\RawResponse;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Domain\SourceTypes;
use n5s\Rangelog\Exception\FetchException;
use n5s\Rangelog\Fetcher\FetcherInterface;
use n5s\Rangelog\Tests\TestSupport\FixtureFetcher;

/**
 * Tests for the test-support stub `tests/TestSupport/FixtureFetcher.php`.
 *
 * Contract:
 *  - `final class FixtureFetcher implements FetcherInterface`.
 *  - URL → fixture-path map; misses throw `FetchException(statusCode: 404)`.
 *  - Content-type inference: `.json` → application/json; `.md`/`.txt`
 *    → text/plain; default → application/octet-stream.
 *  - The returned `RawResponse->source` is the SAME `Source` instance
 *    passed to `fetch()` (named-arg `source: $source`).
 */

// ---------------------------------------------------------------------------
// Structure — final class implementing FetcherInterface (Pattern A)
// ---------------------------------------------------------------------------

it('is a final class implementing FetcherInterface', function (): void {
    $reflection = new ReflectionClass(FixtureFetcher::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->implementsInterface(FetcherInterface::class))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 404 paths — URL miss + on-disk miss both throw FetchException(404)
// ---------------------------------------------------------------------------

it('throws FetchException with statusCode 404 when the URL is not mapped', function (): void {
    $fetcher = new FixtureFetcher(urlToFixturePath: []);
    $source = new Source(
        type: SourceTypes::GITHUB_FILE,
        url: 'https://example.com/unmapped.md',
    );

    $caught = null;
    try {
        $fetcher->fetch($source);
    } catch (FetchException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught)->toBeInstanceOf(FetchException::class);
    expect($caught?->statusCode)->toBe(404);
});

it('throws FetchException with statusCode 404 when the mapped fixture is missing on disk', function (): void {
    $fetcher = new FixtureFetcher(urlToFixturePath: [
        'https://example.com/ghost.md' => 'markdown/this-file-does-not-exist.md',
    ]);
    $source = new Source(
        type: SourceTypes::GITHUB_FILE,
        url: 'https://example.com/ghost.md',
    );

    $caught = null;
    try {
        $fetcher->fetch($source);
    } catch (FetchException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught?->statusCode)->toBe(404);
});

// ---------------------------------------------------------------------------
// Happy path — body bytes match the file under tests/Fixtures/
// ---------------------------------------------------------------------------

it('returns a RawResponse whose body matches the fixture file contents byte-for-byte', function (): void {
    $fixturePath = __DIR__ . '/../../Fixtures/integration/fixture-fetcher-smoke.txt';
    $expectedBody = file_get_contents($fixturePath);
    expect($expectedBody)->not->toBeFalse();

    $fetcher = new FixtureFetcher(urlToFixturePath: [
        'https://example.com/smoke' => 'integration/fixture-fetcher-smoke.txt',
    ]);
    $source = new Source(
        type: SourceTypes::GITHUB_FILE,
        url: 'https://example.com/smoke',
    );

    $response = $fetcher->fetch($source);

    expect($response)->toBeInstanceOf(RawResponse::class);
    expect($response->body)->toBe($expectedBody);
});

// ---------------------------------------------------------------------------
// Content-type inference — .json / .md / .txt / unknown
// ---------------------------------------------------------------------------

it('infers application/json for .json fixtures', function (): void {
    $fetcher = new FixtureFetcher(urlToFixturePath: [
        'https://example.com/pkg.json' => 'packagist/symfony-console.json',
    ]);
    $source = new Source(
        type: SourceTypes::GITHUB_RELEASES,
        url: 'https://example.com/pkg.json',
    );

    expect($fetcher->fetch($source)->contentType)->toBe('application/json');
});

it('infers text/plain for .md fixtures', function (): void {
    $fetcher = new FixtureFetcher(urlToFixturePath: [
        'https://example.com/CHANGELOG.md' => 'markdown/kac-descending.md',
    ]);
    $source = new Source(
        type: SourceTypes::GITHUB_FILE,
        url: 'https://example.com/CHANGELOG.md',
    );

    expect($fetcher->fetch($source)->contentType)->toBe('text/plain');
});

it('infers text/plain for .txt fixtures', function (): void {
    $fetcher = new FixtureFetcher(urlToFixturePath: [
        'https://example.com/smoke' => 'integration/fixture-fetcher-smoke.txt',
    ]);
    $source = new Source(
        type: SourceTypes::GITHUB_FILE,
        url: 'https://example.com/smoke',
    );

    expect($fetcher->fetch($source)->contentType)->toBe('text/plain');
});

it('falls back to application/octet-stream for unknown extensions', function (): void {
    $fetcher = new FixtureFetcher(urlToFixturePath: [
        'https://example.com/binary' => 'integration/fixture-fetcher-smoke.bin',
    ]);
    $source = new Source(
        type: SourceTypes::GITHUB_FILE,
        url: 'https://example.com/binary',
    );

    expect($fetcher->fetch($source)->contentType)->toBe('application/octet-stream');
});

// ---------------------------------------------------------------------------
// Source pass-through — RawResponse->source is the SAME instance.
// ---------------------------------------------------------------------------

it('returns a RawResponse whose source is the SAME instance passed to fetch()', function (): void {
    $fetcher = new FixtureFetcher(urlToFixturePath: [
        'https://example.com/smoke' => 'integration/fixture-fetcher-smoke.txt',
    ]);
    $source = new Source(
        type: SourceTypes::GITHUB_FILE,
        url: 'https://example.com/smoke',
    );

    $response = $fetcher->fetch($source);

    expect($response->source)->toBe($source);
});
