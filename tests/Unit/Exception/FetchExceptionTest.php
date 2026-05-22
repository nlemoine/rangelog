<?php

declare(strict_types=1);

namespace n5s\Rangelog\Tests\Unit\Exception;

use n5s\Rangelog\Exception\ChangelogException;
use n5s\Rangelog\Exception\FetchException;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

it('accepts statusCode via named arg and stores as public readonly int', function (): void {
    $exception = new FetchException(statusCode: 503);

    expect($exception->statusCode)->toBe(503);

    $property = new ReflectionProperty(FetchException::class, 'statusCode');
    expect($property->isReadOnly())->toBeTrue();
    expect($property->isPublic())->toBeTrue();
    expect((string) $property->getType())->toBe('int');
});

it('accepts bodyExcerpt via named arg and stores as public readonly string', function (): void {
    $exception = new FetchException(bodyExcerpt: 'oops');

    expect($exception->bodyExcerpt)->toBe('oops');

    $property = new ReflectionProperty(FetchException::class, 'bodyExcerpt');
    expect($property->isReadOnly())->toBeTrue();
    expect($property->isPublic())->toBeTrue();
    expect((string) $property->getType())->toBe('string');
});

it('defaults statusCode to 0 and bodyExcerpt to empty string', function (): void {
    $exception = new FetchException();

    expect($exception->statusCode)->toBe(0);
    expect($exception->bodyExcerpt)->toBe('');
});

it('preserves standard PHP exception positional order (message, code, previous)', function (): void {
    $prior = new RuntimeException('inner');
    $exception = new FetchException('msg', 42, $prior, 500, 'body');

    expect($exception->getMessage())->toBe('msg');
    expect($exception->getCode())->toBe(42);
    expect($exception->getPrevious())->toBe($prior);
    expect($exception->statusCode)->toBe(500);
    expect($exception->bodyExcerpt)->toBe('body');
});

it('is still a final class extending ChangelogException', function (): void {
    $reflection = new ReflectionClass(FetchException::class);

    expect($reflection->isFinal())->toBeTrue();

    $parent = $reflection->getParentClass();
    expect($parent)->not->toBeFalse();
    expect($parent === false ? null : $parent->getName())->toBe(ChangelogException::class);
});

it('can still be caught as ChangelogException (catch-matrix unchanged)', function (): void {
    /** @var ChangelogException|null $caught */
    $caught = null;
    try {
        throw new FetchException(statusCode: 502, bodyExcerpt: 'bad gateway');
    } catch (ChangelogException $e) {
        $caught = $e;
    }

    // Pin the runtime type so PHPStan can read the FetchException-only fields
    // below. The catch clause widens to ChangelogException (catch-matrix
    // routing contract); the instanceof check narrows back.
    expect($caught)->toBeInstanceOf(FetchException::class);
    if (! $caught instanceof FetchException) {
        return; // expect() above already failed; this guard only narrows for PHPStan
    }
    expect($caught->statusCode)->toBe(502);
    expect($caught->bodyExcerpt)->toBe('bad gateway');
});

it('passes message + code + previous through to RuntimeException', function (): void {
    $inner = new RuntimeException('inner');
    $exception = new FetchException('msg', 42, $inner);

    expect($exception->getMessage())->toBe('msg');
    expect($exception->getCode())->toBe(42);
    expect($exception->getPrevious())->toBeInstanceOf(RuntimeException::class);
    expect($exception->getPrevious())->toBe($inner);
});
