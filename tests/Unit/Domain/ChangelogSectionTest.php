<?php

declare(strict_types=1);

use n5s\Rangelog\Domain\ChangelogSection;

it('constructs with title and lines', function (): void {
    $section = new ChangelogSection(title: 'Added', lines: ['Foo', 'Bar']);
    expect($section->title)->toBe('Added');
    expect($section->lines)->toBe(['Foo', 'Bar']);
});

it('exposes public properties', function (): void {
    $section = new ChangelogSection(title: 'Fixed', lines: []);
    expect($section->title)->toBe('Fixed');
    expect($section->lines)->toBe([]);
});

it('is final and readonly', function (): void {
    $reflection = new ReflectionClass(ChangelogSection::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();
});
