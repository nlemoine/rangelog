<?php

declare(strict_types=1);

use n5s\Rangelog\Domain\ChangelogEntry;
use n5s\Rangelog\Domain\ChangelogSection;

it('constructs with all five fields', function (): void {
    $section = new ChangelogSection(title: 'Added', lines: ['New thing']);
    $date = new DateTimeImmutable('2026-01-15');
    $entry = new ChangelogEntry(
        version: '1.2.3',
        date: $date,
        sections: [$section],
        raw: '## 1.2.3\n### Added\n- New thing',
        sourceUrl: 'https://example.com/CHANGELOG.md',
    );
    expect($entry->version)->toBe('1.2.3');
    expect($entry->date)->toBe($date);
    expect($entry->sections)->toBe([$section]);
    expect($entry->raw)->toBe('## 1.2.3\n### Added\n- New thing');
    expect($entry->sourceUrl)->toBe('https://example.com/CHANGELOG.md');
});

it('defaults sourceUrl to null', function (): void {
    $entry = new ChangelogEntry(version: '1.0.0', date: null, sections: [], raw: '');
    expect($entry->sourceUrl)->toBeNull();
});

it('accepts null date', function (): void {
    $entry = new ChangelogEntry(version: '1.0.0', date: null, sections: [], raw: '');
    expect($entry->date)->toBeNull();
});

it('exposes public properties', function (): void {
    $entry = new ChangelogEntry(version: '1.0.0', date: null, sections: [], raw: 'raw text');
    expect($entry->version)->toBe('1.0.0');
    expect($entry->raw)->toBe('raw text');
});

it('is final and readonly', function (): void {
    $reflection = new ReflectionClass(ChangelogEntry::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();
});
