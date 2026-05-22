<?php

declare(strict_types=1);

namespace n5s\Rangelog\Tests\Unit\Fetcher;

use n5s\Rangelog\Domain\RawResponse;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Fetcher\CachedResponse;
use ReflectionClass;
use ReflectionProperty;

/**
 * CachedResponse is an @internal value object stored under a single PSR-16
 * cache key: bundles the buffered RawResponse with an optional ETag.
 * Callers never see this type — CachingFetcher::fetch() always returns
 * RawResponse.
 */
it('is a final readonly class', function (): void {
    $reflection = new ReflectionClass(CachedResponse::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();
});

it('exposes RawResponse $response and ?string $etag as public readonly properties', function (): void {
    $reflection = new ReflectionClass(CachedResponse::class);
    $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

    expect($properties)->toHaveCount(2);

    $responseProp = new ReflectionProperty(CachedResponse::class, 'response');
    expect($responseProp->isPublic())->toBeTrue();
    expect($responseProp->isReadOnly())->toBeTrue();
    expect((string) $responseProp->getType())->toBe(RawResponse::class);

    $etagProp = new ReflectionProperty(CachedResponse::class, 'etag');
    expect($etagProp->isPublic())->toBeTrue();
    expect($etagProp->isReadOnly())->toBeTrue();
    expect((string) $etagProp->getType())->toBe('?string');
});

it('accepts a non-null etag string via constructor', function (): void {
    $response = new RawResponse('body', 'text/plain', new Source('github_releases', 'https://example.com/r'));
    $cached = new CachedResponse(response: $response, etag: '"abc"');

    expect($cached->response)->toBe($response);
    expect($cached->etag)->toBe('"abc"');
});

it('accepts null etag via constructor', function (): void {
    $response = new RawResponse('body', 'text/plain', new Source('github_releases', 'https://example.com/r'));
    $cached = new CachedResponse(response: $response, etag: null);

    expect($cached->response)->toBe($response);
    expect($cached->etag)->toBeNull();
});

it('is documented @internal', function (): void {
    $reflection = new ReflectionClass(CachedResponse::class);
    $docComment = $reflection->getDocComment();

    expect($docComment)->not->toBeFalse();
    /** @var string $docComment */
    expect($docComment)->toContain('@internal');
});
