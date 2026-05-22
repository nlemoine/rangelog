<?php

declare(strict_types=1);

use n5s\Rangelog\Domain\Changelog;
use n5s\Rangelog\Domain\ChangelogEntry;
use n5s\Rangelog\Domain\ChangelogSection;
use n5s\Rangelog\Domain\RawResponse;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Domain\SourceTypes;
use n5s\Rangelog\Domain\VersionRange;
use n5s\Rangelog\Exception\ParseException;
use n5s\Rangelog\Parser\ChangelogParserInterface;
use n5s\Rangelog\Parser\MarkdownParser;
use n5s\Rangelog\Tests\TestSupport\ArrayLogger;

/**
 * Load a markdown fixture into a RawResponse keyed by SourceTypes::GITHUB_FILE.
 *
 * Returns a typed value so the it() blocks stay PHPStan-clean. Top-level
 * helper function, no beforeEach + $this->X shared state.
 */
function loadMarkdownFixture(string $relPath, string $url = 'https://example.com/CHANGELOG.md'): RawResponse
{
    $path = __DIR__ . '/../../Fixtures/markdown/' . $relPath;
    $body = @file_get_contents($path);
    if ($body === false) {
        throw new LogicException("Fixture not found: {$path}");
    }

    return new RawResponse(
        body: $body,
        contentType: 'text/markdown',
        source: new Source(type: SourceTypes::GITHUB_FILE, url: $url),
    );
}

// ---------------------------------------------------------------------------
// Structure
// ---------------------------------------------------------------------------

it('is a final class implementing ChangelogParserInterface', function (): void {
    $reflection = new ReflectionClass(MarkdownParser::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->implementsInterface(ChangelogParserInterface::class))->toBeTrue();
});

it('accepts an optional LoggerInterface and defaults to NullLogger', function (): void {
    // No-arg construction must succeed (NullLogger default).
    $parserDefault = new MarkdownParser();
    expect($parserDefault)->toBeInstanceOf(MarkdownParser::class);

    // Explicit ArrayLogger injection must also succeed.
    $parserInjected = new MarkdownParser(new ArrayLogger());
    expect($parserInjected)->toBeInstanceOf(MarkdownParser::class);
});

// ---------------------------------------------------------------------------
// Keep-a-Changelog descending, Unreleased skip, date suffix
// ---------------------------------------------------------------------------

it('parses kac-descending.md emitting two entries 1.2.3 and 1.2.0', function (): void {
    $parser = new MarkdownParser();
    $changelog = $parser->parse(
        loadMarkdownFixture('kac-descending.md'),
        VersionRange::changes('0.0.0', '99.0.0'),
    );

    expect($changelog)->toBeInstanceOf(Changelog::class);
    expect($changelog->entries)->toHaveCount(2);

    $versions = array_map(static fn (ChangelogEntry $e): string => $e->version, $changelog->entries);
    sort($versions);
    expect($versions)->toBe(['1.2.0', '1.2.3']);

    // Dates extracted from the bracket+date heading.
    $byVersion = [];
    foreach ($changelog->entries as $entry) {
        $byVersion[$entry->version] = $entry;
    }
    expect($byVersion['1.2.3']->date)->toBeInstanceOf(DateTimeImmutable::class);
    expect($byVersion['1.2.3']->date?->format('Y-m-d'))->toBe('2026-01-15');
    expect($byVersion['1.2.0']->date?->format('Y-m-d'))->toBe('2025-12-01');

    // Each entry carries exactly one ChangelogSection with empty title.
    foreach ($changelog->entries as $entry) {
        expect($entry->sections)->toHaveCount(1);
        expect($entry->sections[0])->toBeInstanceOf(ChangelogSection::class);
        expect($entry->sections[0]->title)->toBe('');
    }
});

// ---------------------------------------------------------------------------
// Sort-equivalence between ascending and descending fixtures
// ---------------------------------------------------------------------------

it('parses kac-ascending.md producing the same set of versions as kac-descending.md', function (): void {
    $parser = new MarkdownParser();

    $descResult = $parser->parse(
        loadMarkdownFixture('kac-descending.md'),
        VersionRange::changes('0.0.0', '99.0.0'),
    );
    $ascResult = $parser->parse(
        loadMarkdownFixture('kac-ascending.md'),
        VersionRange::changes('0.0.0', '99.0.0'),
    );

    $descVersions = array_map(static fn (ChangelogEntry $e): string => $e->version, $descResult->entries);
    $ascVersions = array_map(static fn (ChangelogEntry $e): string => $e->version, $ascResult->entries);
    sort($descVersions);
    sort($ascVersions);

    expect($descVersions)->toBe($ascVersions);
    expect($descVersions)->toBe(['1.2.0', '1.2.3']);
});

// ---------------------------------------------------------------------------
// v-prefix stripped, bracket+date variant
// ---------------------------------------------------------------------------

it('parses v-prefixed.md stripping the leading v from version strings', function (): void {
    $parser = new MarkdownParser();
    $changelog = $parser->parse(
        loadMarkdownFixture('v-prefixed.md'),
        VersionRange::changes('0.0.0', '99.0.0'),
    );

    expect($changelog->entries)->not->toBeEmpty();

    foreach ($changelog->entries as $entry) {
        expect($entry->version)->not->toStartWith('v');
    }

    $versions = array_map(static fn (ChangelogEntry $e): string => $e->version, $changelog->entries);
    // Fixture carries v8.0.9, v8.0.8, [2.8.2] - 2026-03-19, [2.8.1] - 2026-03-05.
    expect($versions)->toContain('8.0.9');
    expect($versions)->toContain('8.0.8');
    expect($versions)->toContain('2.8.2');
    expect($versions)->toContain('2.8.1');
});

// ---------------------------------------------------------------------------
// Date-suffixed heading without brackets
// ---------------------------------------------------------------------------

it('parses date-suffixed.md populating ChangelogEntry::$date for `## 1.0.0 - 2024-10-01`', function (): void {
    $parser = new MarkdownParser();
    $changelog = $parser->parse(
        loadMarkdownFixture('date-suffixed.md'),
        VersionRange::changes('0.0.0', '99.0.0'),
    );

    $byVersion = [];
    foreach ($changelog->entries as $entry) {
        $byVersion[$entry->version] = $entry;
    }

    expect($byVersion)->toHaveKey('1.0.0');
    expect($byVersion['1.0.0']->date)->toBeInstanceOf(DateTimeImmutable::class);
    expect($byVersion['1.0.0']->date?->format('Y-m-d'))->toBe('2024-10-01');

    expect($byVersion)->toHaveKey('1.1.0');
    expect($byVersion['1.1.0']->date?->format('Y-m-d'))->toBe('2025-03-15');
});

// ---------------------------------------------------------------------------
// Setext: `Version X.Y.Z\n=====` recognised as a Heading just like ATX
// ---------------------------------------------------------------------------

it('parses setext-headers.md treating setext `Version 1.2.3\\n=====` as a version heading', function (): void {
    $parser = new MarkdownParser();
    $changelog = $parser->parse(
        loadMarkdownFixture('setext-headers.md'),
        VersionRange::changes('0.0.0', '99.0.0'),
    );

    $versions = array_map(static fn (ChangelogEntry $e): string => $e->version, $changelog->entries);
    sort($versions);

    expect($versions)->toBe(['1.2.0', '1.2.3']);
});

// ---------------------------------------------------------------------------
// Yoast non-KAC format — bare `## 27.5` + `Release date: YYYY-MM-DD`
// ---------------------------------------------------------------------------

it('parses yoast-changelog-md.md ignoring the setext title block and emitting bare version entries with Release-date metadata', function (): void {
    $parser = new MarkdownParser();
    $changelog = $parser->parse(
        loadMarkdownFixture('yoast-changelog-md.md'),
        VersionRange::changes('0.0.0', '99.0.0'),
    );

    $versions = array_map(static fn (ChangelogEntry $e): string => $e->version, $changelog->entries);

    // The setext title `Yoast SEO\n=========` and `Changelog\n=========` MUST NOT be entries.
    expect($versions)->not->toContain('Yoast SEO');
    expect($versions)->not->toContain('Changelog');

    // Real bare-version entries must be emitted.
    expect($versions)->toContain('27.5');
    expect($versions)->toContain('27.4');

    // `Release date: 2026-04-28` populates the entry date.
    $byVersion = [];
    foreach ($changelog->entries as $entry) {
        $byVersion[$entry->version] = $entry;
    }
    expect($byVersion['27.5']->date)->toBeInstanceOf(DateTimeImmutable::class);
    expect($byVersion['27.5']->date?->format('Y-m-d'))->toBe('2026-04-28');
    expect($byVersion['27.4']->date?->format('Y-m-d'))->toBe('2026-04-14');
});

// ---------------------------------------------------------------------------
// Non-semver versions silently skipped, logged at debug level
// ---------------------------------------------------------------------------

it('skips non-semver headings emitting one debug "Skipping non-semver version" event per skipped section', function (): void {
    $logger = new ArrayLogger();
    $parser = new MarkdownParser($logger);
    $changelog = $parser->parse(
        loadMarkdownFixture('with-non-semver.md', 'https://example.com/with-non-semver.md'),
        VersionRange::changes('0.0.0', '99.0.0'),
    );

    // Only the real semver entry survives.
    $versions = array_map(static fn (ChangelogEntry $e): string => $e->version, $changelog->entries);
    expect($versions)->toBe(['1.2.3']);

    // Two debug 'Skipping non-semver version' events with correct context.
    $skipRecords = array_values(array_filter(
        $logger->records,
        static fn (array $r): bool => $r['level'] === 'debug'
            && str_contains($r['message'], 'Skipping non-semver version'),
    ));
    expect($skipRecords)->toHaveCount(2);

    $skippedVersions = [];
    foreach ($skipRecords as $record) {
        expect($record['context'])->toHaveKey('version');
        expect($record['context'])->toHaveKey('source');
        expect($record['context']['source'])->toBe('https://example.com/with-non-semver.md');
        $skippedVersions[] = $record['context']['version'];
    }
    sort($skippedVersions);
    expect($skippedVersions)->toBe(['2026-04-28', 'main-branch']);
});

// ---------------------------------------------------------------------------
// Unreleased section skip emits a single debug event
// ---------------------------------------------------------------------------

it('emits exactly one debug "Skipping Unreleased section" event when parsing kac-descending.md', function (): void {
    $logger = new ArrayLogger();
    $parser = new MarkdownParser($logger);
    $parser->parse(
        loadMarkdownFixture('kac-descending.md'),
        VersionRange::changes('0.0.0', '99.0.0'),
    );

    $unreleasedRecords = array_values(array_filter(
        $logger->records,
        static fn (array $r): bool => $r['level'] === 'debug'
            && str_contains($r['message'], 'Skipping Unreleased section'),
    ));

    expect($unreleasedRecords)->toHaveCount(1);
    expect($unreleasedRecords[0]['context'])->toBe([]);
});

// ---------------------------------------------------------------------------
// "Parsed {count} entries from {source}" success event
// ---------------------------------------------------------------------------

it('emits a debug "Parsed {count} entries from {source}" event with the correct count on success', function (): void {
    $logger = new ArrayLogger();
    $parser = new MarkdownParser($logger);
    $parser->parse(
        loadMarkdownFixture('kac-descending.md', 'https://example.com/CHANGELOG.md'),
        VersionRange::changes('0.0.0', '99.0.0'),
    );

    $parsedRecords = array_values(array_filter(
        $logger->records,
        static fn (array $r): bool => $r['level'] === 'debug'
            && str_contains($r['message'], 'Parsed')
            && str_contains($r['message'], 'entries from'),
    ));

    expect($parsedRecords)->toHaveCount(1);
    expect($parsedRecords[0]['context'])->toHaveKey('count');
    expect($parsedRecords[0]['context'])->toHaveKey('source');
    expect($parsedRecords[0]['context']['count'])->toBe(2);
    expect($parsedRecords[0]['context']['source'])->toBe('https://example.com/CHANGELOG.md');
});

// ---------------------------------------------------------------------------
// Empty / unparseable input → ParseException (catch-and-bind idiom)
// ---------------------------------------------------------------------------

it('throws ParseException when the body is empty', function (): void {
    $parser = new MarkdownParser();
    $response = new RawResponse(
        body: '',
        contentType: 'text/markdown',
        source: new Source(type: SourceTypes::GITHUB_FILE, url: 'https://example.com/empty.md'),
    );

    $caught = null;
    try {
        $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));
    } catch (ParseException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ParseException::class);
});

it('throws ParseException when the body contains no version-shaped heading', function (): void {
    $parser = new MarkdownParser();
    $response = new RawResponse(
        body: "Just some prose, no headings.\n\nNo version markers anywhere.\n",
        contentType: 'text/markdown',
        source: new Source(type: SourceTypes::GITHUB_FILE, url: 'https://example.com/prose.md'),
    );

    $caught = null;
    try {
        $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));
    } catch (ParseException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ParseException::class);
});

// ---------------------------------------------------------------------------
// Security: logger context keys restricted to {version, source, count}
// ---------------------------------------------------------------------------

it('NEVER logs context keys outside the allowlist {version, source, count}', function (): void {
    $logger = new ArrayLogger();
    $parser = new MarkdownParser($logger);

    // Exercise every code path that emits a log record: Unreleased skip, non-semver skip,
    // success "Parsed N entries" — covered by parsing kac-descending and with-non-semver.
    $parser->parse(
        loadMarkdownFixture('kac-descending.md'),
        VersionRange::changes('0.0.0', '99.0.0'),
    );
    $parser->parse(
        loadMarkdownFixture('with-non-semver.md'),
        VersionRange::changes('0.0.0', '99.0.0'),
    );

    $allowedKeys = ['version', 'source', 'count'];

    foreach ($logger->records as $record) {
        // No warning/error/notice events in v1.
        expect($record['level'])->toBe('debug');

        foreach ($record['context'] as $key => $value) {
            $keyString = is_string($key) ? $key : (string) $key;
            expect($allowedKeys)->toContain($keyString);
            // Values must be scalar string/int — never PSR-7 messages or full bodies.
            expect(is_string($value) || is_int($value))->toBeTrue();
        }
    }
});

// ----- Body-content regression -----
//
// The 5 it() blocks below assert that per-version body content is captured
// verbatim. Test 1 guards against the setext underline `=============`
// leaking into $entry->raw. Test 2 is the byte-equal verbatim-body proof
// on a known ATX fixture (mirrors the GitHubReleasesParserTest test 5
// pattern). Tests 3, 4, 5 add per-variant no-bleed assertions for
// v-prefixed, date-suffixed, and Yoast bare-version formats.

it('setext body never contains the underline literal (regression guard)', function (): void {
    $parser = new MarkdownParser();
    $changelog = $parser->parse(
        loadMarkdownFixture('setext-headers.md'),
        VersionRange::changes('0.0.0', '99.0.0'),
    );

    $byVersion = [];
    foreach ($changelog->entries as $entry) {
        $byVersion[$entry->version] = $entry;
    }

    expect($byVersion)->toHaveKey('1.2.3');
    expect($byVersion)->toHaveKey('1.2.0');

    foreach ($changelog->entries as $entry) {
        // The 13-character setext underline must NEVER be the first character
        // of $raw and must NEVER appear inside the body.
        expect($entry->raw)->not->toStartWith('=');
        expect($entry->raw)->not->toContain('=============');
        expect($entry->raw)->not->toContain('-------------');
        // Same assertion against the line-walker view of the body.
        $firstLine = $entry->sections[0]->lines[0] ?? '';
        expect($firstLine)->not->toStartWith('=');
    }

    // Real body markers MUST be present in the 1.2.3 entry.
    expect($byVersion['1.2.3']->raw)->toContain('### Added');
    expect($byVersion['1.2.3']->raw)->toContain('Public `Bar::baz()`');
    // No bleed forward into the 1.2.0 entry.
    expect($byVersion['1.2.3']->raw)->not->toContain('Version 1.2.0');
});

it('kac-descending body byte-equal slice matches the fixture verbatim (verbatim-body contract)', function (): void {
    $parser = new MarkdownParser();
    $changelog = $parser->parse(
        loadMarkdownFixture('kac-descending.md'),
        VersionRange::changes('0.0.0', '99.0.0'),
    );

    $byVersion = [];
    foreach ($changelog->entries as $entry) {
        $byVersion[$entry->version] = $entry;
    }
    expect($byVersion)->toHaveKey('1.2.3');

    // Recompute the expected slice from the fixture file itself — keeps the
    // test resilient to fixture line drift while still proving byte-equal
    // capture between the two ATX version headers.
    $fixturePath = __DIR__ . '/../../Fixtures/markdown/kac-descending.md';
    $contents = (string) file_get_contents($fixturePath);
    $fileLines = preg_split('/\r\n|\n|\r/', $contents);
    expect($fileLines)->not->toBeFalse();
    if ($fileLines === false) {
        throw new LogicException('Failed to split fixture into lines');
    }

    $headerIdx = null;
    $nextIdx = null;
    foreach ($fileLines as $idx => $line) {
        if ($headerIdx === null && preg_match('/^## \[1\.2\.3\]/', $line) === 1) {
            $headerIdx = $idx;
            continue;
        }
        if ($headerIdx !== null && preg_match('/^## \[1\.2\.0\]/', $line) === 1) {
            $nextIdx = $idx;
            break;
        }
    }
    expect($headerIdx)->not->toBeNull();
    expect($nextIdx)->not->toBeNull();
    if ($headerIdx === null || $nextIdx === null) {
        throw new LogicException('Could not locate 1.2.3 and 1.2.0 headers in fixture');
    }

    $startIdx = $headerIdx + 1;
    $expectedLines = array_slice($fileLines, $startIdx, $nextIdx - $startIdx);
    $expectedRaw = implode("\n", $expectedLines);

    // Byte-equal contract (mirrors GitHubReleasesParserTest test 5).
    expect($byVersion['1.2.3']->raw)->toBe($expectedRaw);
    expect($byVersion['1.2.3']->sections[0]->lines)->toBe($expectedLines);

    // Content sanity AND no-bleed.
    expect($byVersion['1.2.3']->raw)->toContain('### Added');
    expect($byVersion['1.2.3']->raw)->not->toContain('## [1.2.0]');
    expect($byVersion['1.2.3']->raw)->not->toContain('## [Unreleased]');
});

it('v-prefixed body is non-empty per entry and does not bleed into adjacent entries', function (): void {
    $parser = new MarkdownParser();
    $changelog = $parser->parse(
        loadMarkdownFixture('v-prefixed.md'),
        VersionRange::changes('0.0.0', '99.0.0'),
    );

    $byVersion = [];
    foreach ($changelog->entries as $entry) {
        $byVersion[$entry->version] = $entry;
    }

    expect($byVersion)->toHaveKey('8.0.9');
    expect($byVersion['8.0.9']->raw)->not->toBe('');
    expect($byVersion['8.0.9']->raw)->not->toContain('## v8.0.8');
    expect($byVersion['8.0.9']->raw)->not->toContain('## [2.8.2]');
});

it('date-suffixed body is non-empty per entry and does not bleed backwards or forwards', function (): void {
    $parser = new MarkdownParser();
    $changelog = $parser->parse(
        loadMarkdownFixture('date-suffixed.md'),
        VersionRange::changes('0.0.0', '99.0.0'),
    );

    $byVersion = [];
    foreach ($changelog->entries as $entry) {
        $byVersion[$entry->version] = $entry;
    }

    expect($byVersion)->toHaveKey('1.0.0');
    expect($byVersion)->toHaveKey('1.1.0');

    expect($byVersion['1.0.0']->raw)->not->toBe('');
    expect($byVersion['1.0.0']->raw)->not->toContain('## [1.1.0]');

    expect($byVersion['1.1.0']->raw)->not->toBe('');
    // Descending order: 1.1.0 precedes 1.0.0 in the fixture; the 1.1.0 body
    // must not bleed forward into the 1.0.0 entry header.
    expect($byVersion['1.1.0']->raw)->not->toContain('## [1.0.0]');
});

it('yoast bare-version body retains Release-date metadata and does not bleed into adjacent entries', function (): void {
    $parser = new MarkdownParser();
    $changelog = $parser->parse(
        loadMarkdownFixture('yoast-changelog-md.md'),
        VersionRange::changes('0.0.0', '99.0.0'),
    );

    $byVersion = [];
    foreach ($changelog->entries as $entry) {
        $byVersion[$entry->version] = $entry;
    }

    expect($byVersion)->toHaveKey('27.5');
    // The `Release date:` line documented in the body MUST be captured
    // verbatim — it is the metadata source the parser falls back on for
    // the entry's date when the heading carries no date.
    expect($byVersion['27.5']->raw)->toContain('Release date: 2026-04-28');
    // Cross-check: even for Yoast's bare `## NN.NN` ATX headers, no setext
    // underline literal from the document title block leaks in.
    expect($byVersion['27.5']->raw)->not->toStartWith('=');
    // No bleed forward into the 27.4 entry.
    expect($byVersion['27.5']->raw)->not->toContain('## 27.4');
});
