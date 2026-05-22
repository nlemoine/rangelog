<?php

declare(strict_types=1);

use n5s\Rangelog\Domain\Changelog;
use n5s\Rangelog\Domain\ChangelogEntry;
use n5s\Rangelog\Domain\VersionRange;
use n5s\Rangelog\SourceProvider\PartialResultDetector;

function entry(string $version): ChangelogEntry
{
    return new ChangelogEntry(version: $version, date: null, sections: [], raw: '');
}

it('is a final class with no constructor', function (): void {
    $reflection = new ReflectionClass(PartialResultDetector::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->getConstructor()?->getNumberOfParameters() ?? 0)->toBe(0);
});

it('marks isPartial=true when from version is absent from entries', function (): void {
    $changelog = new Changelog(entries: [
        entry('5.1'),
        entry('5.2'),
    ]);
    $range = VersionRange::changes('4.9', '5.2');

    $result = PartialResultDetector::markPartialIfFromMissing($changelog, $range);

    expect($result->isPartial)->toBeTrue();
    expect($result->partialReason)->toBe('from version 4.9 not present in source');
    // Original is unchanged (immutable):
    expect($changelog->isPartial)->toBeFalse();
});

it('returns the original Changelog when from version is present', function (): void {
    $changelog = new Changelog(entries: [
        entry('4.9'),
        entry('5.0'),
    ]);
    $range = VersionRange::changes('4.9', '5.0');

    $result = PartialResultDetector::markPartialIfFromMissing($changelog, $range);

    expect($result->isPartial)->toBeFalse();
});

it('handles v-prefix version equivalence via composer/semver Comparator', function (): void {
    $changelog = new Changelog(entries: [
        entry('v1.2.3'),
    ]);
    $range = VersionRange::changes('1.2.3', '2.0.0');

    $result = PartialResultDetector::markPartialIfFromMissing($changelog, $range);

    expect($result->isPartial)->toBeFalse();
});

it('preserves existing partialReason when input Changelog is already partial', function (): void {
    $changelog = new Changelog(entries: [], isPartial: true, partialReason: 'prior reason');
    $range = VersionRange::changes('1.0.0', '2.0.0');

    $result = PartialResultDetector::markPartialIfFromMissing($changelog, $range);

    expect($result->partialReason)->toBe('prior reason');
});

it('marks isPartial=true on empty entries (from cannot be present)', function (): void {
    $changelog = new Changelog(entries: []);
    $range = VersionRange::changes('1.0.0', '2.0.0');

    $result = PartialResultDetector::markPartialIfFromMissing($changelog, $range);

    expect($result->isPartial)->toBeTrue();
    expect($result->partialReason)->toContain('from version 1.0.0');
});

it('silently skips non-semver entry versions without throwing', function (): void {
    $changelog = new Changelog(entries: [
        entry('20231015'),
        entry('banana'),
        entry('5.0'),
    ]);
    $range = VersionRange::changes('4.9', '5.0');

    $result = PartialResultDetector::markPartialIfFromMissing($changelog, $range);

    expect($result->isPartial)->toBeTrue();
});
