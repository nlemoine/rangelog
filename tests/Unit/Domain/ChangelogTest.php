<?php

declare(strict_types=1);

use n5s\Rangelog\Domain\Changelog;
use n5s\Rangelog\Domain\ChangelogEntry;
use n5s\Rangelog\Domain\VersionRange;

function makeEntry(string $version): ChangelogEntry
{
    return new ChangelogEntry(
        version: $version,
        date: null,
        sections: [],
        raw: '',
    );
}

it('constructs empty by default', function (): void {
    $changelog = new Changelog([]);
    expect($changelog->entries)->toBe([]);
    expect($changelog->isPartial())->toBeFalse();
    expect($changelog->getPartialReason())->toBeNull();
});

it('reports isPartial and partialReason when set', function (): void {
    $changelog = new Changelog([], isPartial: true, partialReason: 'WP API truncated');
    expect($changelog->isPartial())->toBeTrue();
    expect($changelog->getPartialReason())->toBe('WP API truncated');
});

it('filter with ::changes excludes the from version and includes the to version', function (): void {
    $changelog = new Changelog([
        makeEntry('1.0.0'),
        makeEntry('1.1.0'),
        makeEntry('1.2.0'),
        makeEntry('2.0.0'),
    ]);
    $filtered = $changelog->filter(VersionRange::changes('1.0.0', '2.0.0'));
    $versions = array_map(fn (ChangelogEntry $e): string => $e->version, $filtered->entries);
    expect($versions)->toBe(['1.1.0', '1.2.0', '2.0.0']);
});

it('filter with ::inclusive includes both ends', function (): void {
    $changelog = new Changelog([
        makeEntry('1.0.0'),
        makeEntry('1.1.0'),
        makeEntry('2.0.0'),
    ]);
    $filtered = $changelog->filter(VersionRange::inclusive('1.0.0', '2.0.0'));
    $versions = array_map(fn (ChangelogEntry $e): string => $e->version, $filtered->entries);
    expect($versions)->toBe(['1.0.0', '1.1.0', '2.0.0']);
});

it('filter silently skips non-semver entries', function (): void {
    $changelog = new Changelog([
        makeEntry('1.0.0'),
        makeEntry('not-a-version'),
        makeEntry('1.5.0'),
        makeEntry('2.0.0'),
    ]);
    $filtered = $changelog->filter(VersionRange::changes('1.0.0', '2.0.0'));
    $versions = array_map(fn (ChangelogEntry $e): string => $e->version, $filtered->entries);
    expect($versions)->toBe(['1.5.0', '2.0.0']);
});

it('filter preserves isPartial flag from source', function (): void {
    $changelog = new Changelog(
        [makeEntry('1.5.0'), makeEntry('2.0.0')],
        isPartial: true,
        partialReason: 'truncated',
    );
    $filtered = $changelog->filter(VersionRange::changes('1.0.0', '2.0.0'));
    expect($filtered->isPartial())->toBeTrue();
    expect($filtered->getPartialReason())->toBe('truncated');
});

it('filter returns a new Changelog (immutable)', function (): void {
    $changelog = new Changelog([makeEntry('1.5.0'), makeEntry('2.0.0')]);
    $filtered = $changelog->filter(VersionRange::changes('1.0.0', '2.0.0'));
    expect($filtered)->not->toBe($changelog);
    expect($changelog->entries)->toHaveCount(2);
});

it('filter on empty entries returns empty Changelog', function (): void {
    $changelog = new Changelog([]);
    $filtered = $changelog->filter(VersionRange::changes('1.0.0', '2.0.0'));
    expect($filtered->entries)->toBe([]);
});

it('is final and readonly', function (): void {
    $reflection = new ReflectionClass(Changelog::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();
});

// ---------------------------------------------------------------------------
// v-prefix normalization
// ---------------------------------------------------------------------------

it('filter keeps a v-prefixed entry when the range bounds are unprefixed', function (): void {
    // Comparator::greaterThan('v7.4.8', '7.4.7') returns false (wrong)
    // without VersionParser::normalize() on both sides. Affects ~25 packages
    // (symfony/* components, twig/cache-extra, etc.).
    $changelog = new Changelog([makeEntry('v7.4.7'), makeEntry('v7.4.8'), makeEntry('v7.4.9')]);
    $filtered = $changelog->filter(VersionRange::changes('7.4.7', '7.4.9'));
    $versions = array_map(fn (ChangelogEntry $e): string => $e->version, $filtered->entries);
    expect($versions)->toBe(['v7.4.8', 'v7.4.9']);
});

it('filter keeps an unprefixed entry when the range bounds are v-prefixed', function (): void {
    // Mirror case: entry version has no prefix; range bounds carry v-prefix.
    // Comparator returns wrong results across mixed prefixes in either direction.
    $changelog = new Changelog([makeEntry('7.4.7'), makeEntry('7.4.8'), makeEntry('7.4.9')]);
    $filtered = $changelog->filter(VersionRange::changes('v7.4.7', 'v7.4.9'));
    $versions = array_map(fn (ChangelogEntry $e): string => $e->version, $filtered->entries);
    expect($versions)->toBe(['7.4.8', '7.4.9']);
});

it('filter keeps entries when both entry and range carry v-prefix', function (): void {
    // Same-prefix-both-sides should ALWAYS work — regression sanity check
    // that normalize() does not break the case where Comparator already worked.
    $changelog = new Changelog([makeEntry('v7.4.7'), makeEntry('v7.4.8'), makeEntry('v7.4.9')]);
    $filtered = $changelog->filter(VersionRange::changes('v7.4.7', 'v7.4.9'));
    $versions = array_map(fn (ChangelogEntry $e): string => $e->version, $filtered->entries);
    expect($versions)->toBe(['v7.4.8', 'v7.4.9']);
});

it('filter handles the canonical real-world repro: symfony/console v7.4.7 → v7.4.9', function (): void {
    // GitHubReleasesResolver::resolve() normalizes both sides via
    // VersionParser::normalize() but Changelog::filter() must do the same —
    // the inconsistency causes wrong Comparator results when tag_names carry
    // v-prefix while caller-supplied range bounds do not
    // (examples/from-composer-diff.php strips the v).
    // Descending order matches GitHub API output order (parser returns entries newest-first).
    $changelog = new Changelog([makeEntry('v7.4.9'), makeEntry('v7.4.8')]);
    $filtered = $changelog->filter(VersionRange::changes('7.4.7', '7.4.9'));
    $versions = array_map(fn (ChangelogEntry $e): string => $e->version, $filtered->entries);
    expect($versions)->toBe(['v7.4.9', 'v7.4.8']);
});

// ---------------------------------------------------------------------------
// Silent-drop contract on non-semver range bounds
// ---------------------------------------------------------------------------

it('filter silently drops all entries when range->from is non-semver', function (): void {
    $changelog = new Changelog([makeEntry('1.5.0'), makeEntry('2.0.0')]);
    // Non-semver from bound: VersionParser::normalize() throws
    // UnexpectedValueException → every entry is dropped (silent-drop per
    // catch in matches()).
    $filtered = $changelog->filter(new VersionRange('not-a-version', '2.0.0'));
    expect($filtered->entries)->toBe([]);
});

it('filter silently drops all entries when range->to is non-semver', function (): void {
    $changelog = new Changelog([makeEntry('1.5.0'), makeEntry('2.0.0')]);
    $filtered = $changelog->filter(new VersionRange('1.0.0', 'not-a-version'));
    expect($filtered->entries)->toBe([]);
});
