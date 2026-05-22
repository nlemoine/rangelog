<?php

declare(strict_types=1);

use Composer\Semver\VersionParser;
use n5s\Rangelog\Domain\Changelog;
use n5s\Rangelog\Domain\ChangelogEntry;
use n5s\Rangelog\Domain\RawResponse;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Domain\SourceTypes;
use n5s\Rangelog\Domain\VersionRange;
use n5s\Rangelog\Exception\ParseException;
use n5s\Rangelog\Parser\ChangelogParserInterface;
use n5s\Rangelog\Parser\WordPressReadmeParser;
use n5s\Rangelog\Tests\TestSupport\ArrayLogger;

/**
 * Build a RawResponse from a fixture file under tests/Fixtures/wp/.
 *
 * URL defaults to the canonical WP.org SVN trunk URL for the plugin slug.
 * Plugin slug is the basename of $relPath with `.readme.txt` stripped.
 */
function loadWpFixture(string $relPath, ?string $url = null): RawResponse
{
    $path = __DIR__ . '/../../Fixtures/wp/' . $relPath;
    $body = file_get_contents($path);
    if ($body === false) {
        throw new LogicException('Unable to load fixture: ' . $path);
    }

    $slug = (string) preg_replace('/\.readme\.txt$/', '', $relPath);
    $defaultUrl = 'https://plugins.svn.wordpress.org/' . $slug . '/trunk/readme.txt';

    return new RawResponse(
        body: $body,
        contentType: 'text/plain',
        source: new Source(
            type: SourceTypes::WORDPRESS_ORG,
            url: $url ?? $defaultUrl,
        ),
    );
}

// ---------------------------------------------------------------------------
// Structure & contract
// ---------------------------------------------------------------------------

it('is a final class implementing ChangelogParserInterface', function (): void {
    $reflection = new ReflectionClass(WordPressReadmeParser::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->implementsInterface(ChangelogParserInterface::class))->toBeTrue();
});

it('constructor accepts no args (NullLogger default) and an optional LoggerInterface', function (): void {
    $defaultParser = new WordPressReadmeParser();
    expect($defaultParser)->toBeInstanceOf(WordPressReadmeParser::class);

    $logger = new ArrayLogger();
    $withLogger = new WordPressReadmeParser($logger);
    expect($withLogger)->toBeInstanceOf(WordPressReadmeParser::class);
});

// ---------------------------------------------------------------------------
// Real-world fixtures
// ---------------------------------------------------------------------------

it('parses wordpress-seo.readme.txt clean semver entries (Yoast SEO)', function (): void {
    $parser = new WordPressReadmeParser(new ArrayLogger());
    $response = loadWpFixture('wordpress-seo.readme.txt');

    $result = $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    expect($result)->toBeInstanceOf(Changelog::class);
    expect($result->entries)->not->toBeEmpty();
    expect($result->entries[0])->toBeInstanceOf(ChangelogEntry::class);
    // No v-prefix in the raw version string for Yoast.
    expect($result->entries[0]->version)->not->toStartWith('v');
    // Yoast headers are clean semver — at minimum the first entry must be a 2-or-3-component number.
    expect($result->entries[0]->version)->toMatch('/^\d+\.\d+(?:\.\d+)?$/');

    $versions = array_map(static fn (ChangelogEntry $e): string => $e->version, $result->entries);
    expect($versions)->toContain('27.6');
});

it('parses contact-form-7.readme.txt clean semver entries', function (): void {
    $parser = new WordPressReadmeParser(new ArrayLogger());
    $response = loadWpFixture('contact-form-7.readme.txt');

    $result = $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    $versions = array_map(static fn (ChangelogEntry $e): string => $e->version, $result->entries);
    expect($versions)->toContain('6.1.5');
});

it('parses woocommerce.readme.txt space-date variant with captured date', function (): void {
    $parser = new WordPressReadmeParser(new ArrayLogger());
    $response = loadWpFixture('woocommerce.readme.txt');

    $result = $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    expect($result->entries)->not->toBeEmpty();

    // Find the 10.8.0-beta.2 entry.
    $betaEntry = null;
    foreach ($result->entries as $entry) {
        if ($entry->version === '10.8.0-beta.2') {
            $betaEntry = $entry;
            break;
        }
    }
    expect($betaEntry)->toBeInstanceOf(ChangelogEntry::class);
    // PHPStan narrowing: re-bind after the expect-not-null assertion above.
    if (! $betaEntry instanceof ChangelogEntry) {
        throw new LogicException('Expected 10.8.0-beta.2 entry from woocommerce fixture');
    }
    expect($betaEntry->date)->not->toBeNull();
    expect($betaEntry->date?->format('Y-m-d'))->toBe('2026-05-11');
});

it('parses advanced-custom-fields.readme.txt 4-component versions', function (): void {
    $parser = new WordPressReadmeParser(new ArrayLogger());
    $response = loadWpFixture('advanced-custom-fields.readme.txt');

    $result = $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    $versions = array_map(static fn (ChangelogEntry $e): string => $e->version, $result->entries);
    // 4-component version preserved verbatim in the entry (normalisation is internal to the semver check).
    expect($versions)->toContain('6.7.0.2');
});

it('parses jetpack.readme.txt without picking up Description-section marketing copy', function (): void {
    $parser = new WordPressReadmeParser(new ArrayLogger());
    $response = loadWpFixture('jetpack.readme.txt');

    $result = $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    // Always-on assertion: parse must succeed (no ParseException) AND return a Changelog.
    // Jetpack's own `== Changelog ==` body uses `###` markdown-style headers rather than
    // the WP `= 1.2.3 =` dialect, so the parser returns a (possibly empty) entries list —
    // never throws, never picks up Description-section `= 24/7 AUTO SITE SECURITY =` copy.
    expect($result)->toBeInstanceOf(Changelog::class);

    // Every entry (if any) MUST be from inside `== Changelog ==`, i.e. semver-normalisable
    // and free of marketing-copy strings from `== Description ==`.
    $versionParser = new VersionParser();
    foreach ($result->entries as $entry) {
        expect(stripos($entry->version, 'AUTO'))->toBe(false);
        expect(stripos($entry->version, 'SECURITY'))->toBe(false);
        expect(stripos($entry->version, 'JETPACK'))->toBe(false);

        // Every returned version must normalise cleanly as a semver — proves boundary detection.
        $versionParser->normalize($entry->version);
    }

    // Critically: NONE of the marketing-copy strings from `== Description ==` ever
    // appear as an entry, whether the entries list is empty or populated.
    $versions = array_map(static fn (ChangelogEntry $e): string => $e->version, $result->entries);
    expect($versions)->not->toContain('24/7 AUTO SITE SECURITY');
    expect($versions)->not->toContain('PEAK SPEED AND PERFORMANCE');
});

// ---------------------------------------------------------------------------
// Boundary detection (synthetic)
// ---------------------------------------------------------------------------

it('stops collecting at the next top-level section boundary (Upgrade Notice)', function (): void {
    $parser = new WordPressReadmeParser(new ArrayLogger());
    $body = "== Changelog ==\n= 1.0.0 =\nBody line one\nBody line two\n== Upgrade Notice ==\n= 2.0.0 =\nNotice body\n";
    $response = new RawResponse(
        body: $body,
        contentType: 'text/plain',
        source: new Source(type: SourceTypes::WORDPRESS_ORG, url: 'https://example.com/readme.txt'),
    );

    $result = $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    expect($result->entries)->toHaveCount(1);
    expect($result->entries[0]->version)->toBe('1.0.0');

    $versions = array_map(static fn (ChangelogEntry $e): string => $e->version, $result->entries);
    expect($versions)->not->toContain('2.0.0');
});

// ---------------------------------------------------------------------------
// Non-semver header skip logs once at debug
// ---------------------------------------------------------------------------

it('skips non-semver version headers and logs one debug "Skipping non-semver version" event', function (): void {
    $logger = new ArrayLogger();
    $parser = new WordPressReadmeParser($logger);
    $url = 'https://example.com/readme.txt';

    $body = "== Changelog ==\n= Initial release =\nNotes line\n= 1.0.0 =\nBody line\n";
    $response = new RawResponse(
        body: $body,
        contentType: 'text/plain',
        source: new Source(type: SourceTypes::WORDPRESS_ORG, url: $url),
    );

    $result = $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    expect($result->entries)->toHaveCount(1);
    expect($result->entries[0]->version)->toBe('1.0.0');

    $skipRecords = array_values(array_filter(
        $logger->records,
        static fn (array $r): bool => $r['level'] === 'debug' && $r['message'] === 'Skipping non-semver version',
    ));
    expect($skipRecords)->toHaveCount(1);
    expect($skipRecords[0]['context'])->toHaveKey('version');
    expect($skipRecords[0]['context']['version'])->toBe('Initial release');
    expect($skipRecords[0]['context']['source'])->toBe($url);
});

// ---------------------------------------------------------------------------
// Empty Changelog section returns empty Changelog, NOT exception
// ---------------------------------------------------------------------------

it('returns empty Changelog when the Changelog section is present but contains no version headers', function (): void {
    $logger = new ArrayLogger();
    $parser = new WordPressReadmeParser($logger);
    $url = 'https://example.com/readme.txt';

    $body = "== Changelog ==\n== Upgrade Notice ==\nFoo\n";
    $response = new RawResponse(
        body: $body,
        contentType: 'text/plain',
        source: new Source(type: SourceTypes::WORDPRESS_ORG, url: $url),
    );

    $threw = false;
    $result = null;
    try {
        $result = $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));
    } catch (ParseException) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
    expect($result)->toBeInstanceOf(Changelog::class);
    // PHPStan narrowing — re-bind $result after the instanceof assertion above.
    if (! $result instanceof Changelog) {
        throw new LogicException('Expected non-null Changelog from empty-Changelog-section input');
    }
    expect($result->entries)->toBe([]);

    $successRecords = array_values(array_filter(
        $logger->records,
        static fn (array $r): bool => $r['level'] === 'debug' && $r['message'] === 'Parsed {count} entries from {source}',
    ));
    expect($successRecords)->toHaveCount(1);
    expect($successRecords[0]['context'])->toHaveKey('count');
    expect($successRecords[0]['context']['count'])->toBe(0);
    expect($successRecords[0]['context']['source'])->toBe($url);
});

// ---------------------------------------------------------------------------
// Empty-input threshold — no `== Changelog ==` header throws
// ---------------------------------------------------------------------------

it('throws ParseException when no Changelog section is present', function (): void {
    $parser = new WordPressReadmeParser(new ArrayLogger());
    $body = "== Description ==\nFoo\n== Installation ==\nBar\n";
    $response = new RawResponse(
        body: $body,
        contentType: 'text/plain',
        source: new Source(type: SourceTypes::WORDPRESS_ORG, url: 'https://example.com/readme.txt'),
    );

    $caught = null;
    try {
        $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));
    } catch (ParseException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ParseException::class);
    expect($caught?->getMessage())->toContain('Changelog');
});

// ---------------------------------------------------------------------------
// Success event on every parse
// ---------------------------------------------------------------------------

it('logs one "Parsed {count} entries from {source}" debug record on every successful parse', function (): void {
    $logger = new ArrayLogger();
    $parser = new WordPressReadmeParser($logger);
    $response = loadWpFixture('contact-form-7.readme.txt');

    $result = $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    $successRecords = array_values(array_filter(
        $logger->records,
        static fn (array $r): bool => $r['level'] === 'debug' && $r['message'] === 'Parsed {count} entries from {source}',
    ));
    expect($successRecords)->toHaveCount(1);
    expect($successRecords[0]['context']['count'])->toBe(count($result->entries));
    expect($successRecords[0]['context']['source'])->toBe($response->source->url);
});

// ---------------------------------------------------------------------------
// Logger context purity (security/leakage)
// ---------------------------------------------------------------------------

it('never logs context keys outside {version, source, count}', function (): void {
    $logger = new ArrayLogger();
    $parser = new WordPressReadmeParser($logger);

    // Use the Yoast fixture (plus an extra synthetic non-semver header) so both the
    // success event and a non-semver-skip event get exercised in one run.
    $base = (string) file_get_contents(__DIR__ . '/../../Fixtures/wp/wordpress-seo.readme.txt');
    // Append an extra non-semver header at the END of the existing Changelog section is risky
    // because we don't know the section's exact boundary. Instead, use a synthetic body that
    // exercises both events deterministically.
    $body = "== Changelog ==\n= Initial release =\nNotes\n= 1.0.0 =\nBody\n";
    $response = new RawResponse(
        body: $body,
        contentType: 'text/plain',
        source: new Source(type: SourceTypes::WORDPRESS_ORG, url: 'https://example.com/readme.txt'),
    );

    $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    $allowedKeys = ['version', 'source', 'count'];
    foreach ($logger->records as $record) {
        foreach (array_keys($record['context']) as $key) {
            expect($allowedKeys)->toContain((string) $key);
        }
    }

    expect($base)->not->toBeEmpty(); // sanity-touch the fixture path
});

// ----- Body-content regression -----
//
// The 4 it() blocks below assert that per-version body content is captured
// verbatim AND that accidental zero-whitespace literals like `=foo=` or
// `=mid-body=` do NOT match as candidate headers. The naive
// CANDIDATE_HEADER regex (`/^=\s*([^=]+?)\s*=\s*$/`) treats `=foo=` as a
// header and silently truncates the surrounding entry's body.

it('mid-body literal does not truncate the entry body (=mid-body= regression)', function (): void {
    $parser = new WordPressReadmeParser(new ArrayLogger());
    $body = "== Changelog ==\n"
        . "= 1.0.0 =\n"
        . "First body line.\n"
        . "=mid-body=\n"
        . "Second body line that must be preserved.\n"
        . "Third body line.\n"
        . "= 0.9.0 =\n"
        . "Previous entry body.\n";
    $response = new RawResponse(
        body: $body,
        contentType: 'text/plain',
        source: new Source(type: SourceTypes::WORDPRESS_ORG, url: 'https://example.com/readme.txt'),
    );

    $result = $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    expect($result->entries)->toHaveCount(2);

    $byVersion = [];
    foreach ($result->entries as $entry) {
        $byVersion[$entry->version] = $entry;
    }

    expect($byVersion)->toHaveKey('1.0.0');
    expect($byVersion)->toHaveKey('0.9.0');

    // Load-bearing: the body of the legitimate 1.0.0 entry must be preserved
    // end-to-end past the accidental `=mid-body=` marker.
    expect($byVersion['1.0.0']->raw)->toContain('First body line.');
    expect($byVersion['1.0.0']->raw)->toContain('Second body line that must be preserved.');
    expect($byVersion['1.0.0']->raw)->toContain('Third body line.');
    expect($byVersion['1.0.0']->raw)->toContain('=mid-body=');
    // No bleed forward into the 0.9.0 entry.
    expect($byVersion['1.0.0']->raw)->not->toContain('Previous entry body.');
    expect($byVersion['0.9.0']->raw)->toContain('Previous entry body.');
});

it('accidental =foo= literal is treated as body content, not a header', function (): void {
    $logger = new ArrayLogger();
    $parser = new WordPressReadmeParser($logger);
    $body = "== Changelog ==\n= 1.0.0 =\nBefore foo.\n=foo=\nAfter foo.\n";
    $response = new RawResponse(
        body: $body,
        contentType: 'text/plain',
        source: new Source(type: SourceTypes::WORDPRESS_ORG, url: 'https://example.com/readme.txt'),
    );

    $result = $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    expect($result->entries)->toHaveCount(1);
    expect($result->entries[0]->version)->toBe('1.0.0');
    expect($result->entries[0]->raw)->toContain('Before foo.');
    expect($result->entries[0]->raw)->toContain('=foo=');
    // Load-bearing: body preserved past the accidental marker.
    expect($result->entries[0]->raw)->toContain('After foo.');

    // No non-semver-skip log fires for `foo` — the regex tightening means
    // `=foo=` never matches CANDIDATE_HEADER at all.
    $fooSkips = array_values(array_filter(
        $logger->records,
        static fn (array $r): bool => $r['level'] === 'debug'
            && $r['message'] === 'Skipping non-semver version'
            && ($r['context']['version'] ?? null) === 'foo',
    ));
    expect($fooSkips)->toBe([]);
});

it('canonical = 1.2.3 = WP version header still matches after the regex tightening', function (): void {
    $parser = new WordPressReadmeParser(new ArrayLogger());
    $body = "== Changelog ==\n= 1.2.3 =\nBody A.\n= 1.2.4 =\nBody B.\n";
    $response = new RawResponse(
        body: $body,
        contentType: 'text/plain',
        source: new Source(type: SourceTypes::WORDPRESS_ORG, url: 'https://example.com/readme.txt'),
    );

    $result = $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    expect($result->entries)->toHaveCount(2);
    $versions = array_map(static fn (ChangelogEntry $e): string => $e->version, $result->entries);
    expect($versions)->toContain('1.2.3');
    expect($versions)->toContain('1.2.4');
});

it('Yoast 27.6 body byte-equal slice matches the fixture verbatim', function (): void {
    $parser = new WordPressReadmeParser(new ArrayLogger());
    $response = loadWpFixture('wordpress-seo.readme.txt');

    $fixturePath = __DIR__ . '/../../Fixtures/wp/wordpress-seo.readme.txt';
    $contents = (string) file_get_contents($fixturePath);
    $fileLines = preg_split('/\r\n|\n|\r/', $contents);
    if ($fileLines === false) {
        throw new LogicException('Failed to split fixture into lines');
    }

    $headerIdx = null;
    $nextIdx = null;
    foreach ($fileLines as $idx => $line) {
        if ($headerIdx === null && preg_match('/^=\s+27\.6\s+=\s*$/', $line) === 1) {
            $headerIdx = $idx;
            continue;
        }
        if ($headerIdx !== null && preg_match('/^=\s+/', $line) === 1) {
            $nextIdx = $idx;
            break;
        }
    }

    // Defensive guard: if the fixture has drifted (27.6 no longer present)
    // we fall back to the first `= NN =` block in the Changelog section so
    // the test still pins the byte-equal contract on whatever version is
    // currently the top entry.
    $targetVersion = '27.6';
    if ($headerIdx === null) {
        foreach ($fileLines as $idx => $line) {
            if (preg_match('/^=\s+(\d+(?:\.\d+){1,3})\s+=\s*$/', $line, $m) === 1) {
                $headerIdx = $idx;
                $targetVersion = $m[1];
                $counter = count($fileLines);
                for ($j = $idx + 1; $j < $counter; ++$j) {
                    if (preg_match('/^=\s+/', $fileLines[$j]) === 1) {
                        $nextIdx = $j;
                        break;
                    }
                }
                break;
            }
        }
    }

    expect($headerIdx)->not->toBeNull();
    expect($nextIdx)->not->toBeNull();
    if ($headerIdx === null || $nextIdx === null) {
        throw new LogicException('Could not locate version header in Yoast fixture');
    }

    $expectedLines = array_slice($fileLines, $headerIdx + 1, $nextIdx - $headerIdx - 1);
    $expectedRaw = implode("\n", $expectedLines);

    $result = $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    $targetEntry = null;
    foreach ($result->entries as $entry) {
        if ($entry->version === $targetVersion) {
            $targetEntry = $entry;
            break;
        }
    }
    expect($targetEntry)->not->toBeNull();
    if (! $targetEntry instanceof ChangelogEntry) {
        throw new LogicException('Expected entry for version ' . $targetVersion);
    }

    // Byte-equal contract (mirrors GitHubReleasesParserTest test 5).
    expect($targetEntry->raw)->toBe($expectedRaw);
    expect($targetEntry->sections[0]->lines)->toBe($expectedLines);
});
