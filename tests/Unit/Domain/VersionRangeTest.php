<?php

declare(strict_types=1);

use n5s\Rangelog\Domain\VersionRange;

it('defaults to exclusive-from / inclusive-to', function (): void {
    $range = new VersionRange(from: '1.0.0', to: '2.0.0');
    expect($range->includeFrom)->toBeFalse();
    expect($range->includeTo)->toBeTrue();
});

it('::changes returns exclusive-from / inclusive-to', function (): void {
    $range = VersionRange::changes('1.0.0', '2.0.0');
    expect($range->from)->toBe('1.0.0');
    expect($range->to)->toBe('2.0.0');
    expect($range->includeFrom)->toBeFalse();
    expect($range->includeTo)->toBeTrue();
});

it('::inclusive returns both-ends-inclusive', function (): void {
    $range = VersionRange::inclusive('1.0.0', '2.0.0');
    expect($range->includeFrom)->toBeTrue();
    expect($range->includeTo)->toBeTrue();
});

it('accepts named-arg overrides', function (): void {
    $range = new VersionRange(from: '1.0.0', to: '2.0.0', includeFrom: true, includeTo: false);
    expect($range->includeFrom)->toBeTrue();
    expect($range->includeTo)->toBeFalse();
});

it('exposes public properties', function (): void {
    $range = VersionRange::changes('1.0.0', '2.0.0');
    expect($range->from)->toBe('1.0.0');
    expect($range->to)->toBe('2.0.0');
});

it('is final and readonly', function (): void {
    $reflection = new ReflectionClass(VersionRange::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();
});
