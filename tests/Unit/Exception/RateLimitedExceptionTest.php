<?php

declare(strict_types=1);

use n5s\Rangelog\Exception\RateLimitedException;

it('defaults retryAfter and rateLimitReset to null', function (): void {
    $exception = new RateLimitedException();
    expect($exception->retryAfter)->toBeNull();
    expect($exception->rateLimitReset)->toBeNull();
});

it('accepts an int retryAfter', function (): void {
    $exception = new RateLimitedException(retryAfter: 60);
    expect($exception->retryAfter)->toBe(60);
});

it('accepts a DateTimeImmutable rateLimitReset', function (): void {
    $reset = new DateTimeImmutable('2026-05-07 14:00:00');
    $exception = new RateLimitedException(rateLimitReset: $reset);
    expect($exception->rateLimitReset)->toBe($reset);
});

it('accepts a message argument', function (): void {
    $exception = new RateLimitedException('Too many requests', retryAfter: 30);
    expect($exception->getMessage())->toBe('Too many requests');
    expect($exception->retryAfter)->toBe(30);
});

it('exposes properties as readable public', function (): void {
    $exception = new RateLimitedException('msg', retryAfter: 10, rateLimitReset: new DateTimeImmutable('2026-01-01'));
    // direct property access (no getter)
    expect($exception->retryAfter)->toBe(10);
    expect($exception->rateLimitReset)->toBeInstanceOf(DateTimeImmutable::class);
});

it('is a final class', function (): void {
    $reflection = new ReflectionClass(RateLimitedException::class);
    expect($reflection->isFinal())->toBeTrue();
});
