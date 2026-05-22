<?php

declare(strict_types=1);

use JsonException;
use n5s\Rangelog\Domain\Changelog;
use n5s\Rangelog\Domain\ChangelogEntry;
use n5s\Rangelog\Domain\ChangelogSection;
use n5s\Rangelog\Domain\RawResponse;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Domain\SourceTypes;
use n5s\Rangelog\Domain\VersionRange;
use n5s\Rangelog\Exception\ParseException;
use n5s\Rangelog\Parser\ChangelogParserInterface;
use n5s\Rangelog\Parser\GitHubReleasesParser;
use n5s\Rangelog\Tests\TestSupport\ArrayLogger;

/**
 * Load a GitHub Releases fixture into a buffered RawResponse. File-scope
 * helper, throws LogicException if the fixture is missing so the test fails
 * loudly rather than silently skipping branches.
 */
function loadGithubFixture(
    string $relPath,
    string $url = 'https://api.github.com/repos/symfony/console/releases',
): RawResponse {
    $absolute = __DIR__ . '/../../Fixtures/github/' . $relPath;

    if (! is_file($absolute)) {
        throw new LogicException("Missing GitHub fixture: {$absolute}");
    }

    $body = file_get_contents($absolute);
    if ($body === false) {
        throw new LogicException("Cannot read GitHub fixture: {$absolute}");
    }

    return new RawResponse(
        body: $body,
        contentType: 'application/json',
        source: new Source(type: SourceTypes::GITHUB_RELEASES, url: $url),
    );
}

// ---------------------------------------------------------------------------
// Structure
// ---------------------------------------------------------------------------

it('is a final class implementing ChangelogParserInterface', function (): void {
    $reflection = new ReflectionClass(GitHubReleasesParser::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->implementsInterface(ChangelogParserInterface::class))->toBeTrue();
});

it('constructs without arguments (NullLogger default) and accepts an optional LoggerInterface', function (): void {
    $defaultParser = new GitHubReleasesParser();
    expect($defaultParser)->toBeInstanceOf(GitHubReleasesParser::class);

    $loggerParser = new GitHubReleasesParser(new ArrayLogger());
    expect($loggerParser)->toBeInstanceOf(GitHubReleasesParser::class);
});

// ---------------------------------------------------------------------------
// Happy path — symfony/console fixture (30 releases, all non-draft)
// ---------------------------------------------------------------------------

it('parses symfony-console-releases.json and emits one ChangelogEntry per non-draft release', function (): void {
    $parser = new GitHubReleasesParser();
    $response = loadGithubFixture('symfony-console-releases.json');

    $result = $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    expect($result)->toBeInstanceOf(Changelog::class);
    // Fixture: 30 entries, all non-draft, all `name == tag_name`, all semver-prefixed.
    expect($result->entries)->toHaveCount(30);
    expect($result->entries)->each->toBeInstanceOf(ChangelogEntry::class);

    // First non-draft release in the fixture is v8.1.0-BETA1 — parser picks
    // `tag_name` (which equals `name` for symfony) and composer/semver accepts it.
    expect($result->entries[0]->version)->toBe('v8.1.0-BETA1');
    expect($result->entries[0]->sourceUrl)->toBe('https://github.com/symfony/console/releases/tag/v8.1.0-BETA1');
});

it('uses tag_name ?? name precedence and parses published_at as ATOM into DateTimeImmutable', function (): void {
    $parser = new GitHubReleasesParser();
    $response = loadGithubFixture('symfony-console-releases.json');

    $result = $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    // entries[1] is v8.0.9 (published 2026-05-01T08:14:47Z, body 154 chars).
    expect($result->entries[1]->version)->toBe('v8.0.9');
    expect($result->entries[1]->date)->toBeInstanceOf(DateTimeImmutable::class);
    expect($result->entries[1]->date?->format(DateTimeInterface::ATOM))
        ->toBe('2026-05-01T08:14:47+00:00');
});

it('preserves release body verbatim in ChangelogEntry.raw and splits on \\n into ChangelogSection.lines', function (): void {
    $parser = new GitHubReleasesParser();
    $response = loadGithubFixture('symfony-console-releases.json');

    $result = $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    // entries[1] is the first release with a non-null body in the fixture.
    $entry = $result->entries[1];
    $expectedBody = "**Changelog** (https://github.com/symfony/console/compare/v8.0.8...v8.0.9)\n\n * bug #63859  Fix shell completion when SHELL_VERBOSITY=-1 (@nicolas-grekas)\n";

    expect($entry->raw)->toBe($expectedBody);
    expect($entry->sections)->toHaveCount(1);
    expect($entry->sections[0])->toBeInstanceOf(ChangelogSection::class);
    expect($entry->sections[0]->title)->toBe('');
    expect($entry->sections[0]->lines)->toBe(explode("\n", $expectedBody));
});

// ---------------------------------------------------------------------------
// Null branches + draft/prerelease + non-semver skip — releases-with-nulls.json
// ---------------------------------------------------------------------------

it('handles releases-with-nulls.json: null name fallback, null body coercion, draft skip, non-semver skip', function (): void {
    $logger = new ArrayLogger();
    $parser = new GitHubReleasesParser($logger);
    $response = loadGithubFixture('releases-with-nulls.json');

    $result = $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    // Fixture: 4 elements.
    //   1) tag=v1.0.0, name=null              → kept, version 'v1.0.0', published 2024-01-01T00:00:00Z
    //   2) tag=v1.1.0, name='Release 1.1.0'   → tag_name='v1.1.0' is semver-valid, so this entry
    //                                            is KEPT (was previously skipped under name-first
    //                                            precedence).
    //   3) draft=true                          → skipped entirely, no log event.
    //   4) tag='rolling', name='Rolling release' → tag_name='rolling' is non-semver, skipped with debug
    //                                              log version='rolling' (under prior precedence the
    //                                              log version was 'Rolling release' from `name`).
    //
    // Net: 2 kept entries (v1.0.0, v1.1.0); 1 non-semver-skip log event for tag='rolling'.
    expect($result->entries)->toHaveCount(2);
    expect($result->entries[0]->version)->toBe('v1.0.0');
    expect($result->entries[0]->date)->toBeInstanceOf(DateTimeImmutable::class);
    expect($result->entries[0]->date?->format(DateTimeInterface::ATOM))
        ->toBe('2024-01-01T00:00:00+00:00');
    expect($result->entries[1]->version)->toBe('v1.1.0');

    // Locate non-semver-skip log records: exactly ONE (element 4 only).
    $nonSemverRecords = array_values(array_filter(
        $logger->records,
        static fn (array $r): bool => $r['message'] === 'Skipping non-semver version',
    ));
    expect($nonSemverRecords)->toHaveCount(1);

    // Version source is tag_name ?? name, so the single non-semver-skip log
    // for element 4 records version='rolling'.
    $loggedVersions = array_map(
        static fn (array $r): mixed => $r['context']['version'] ?? null,
        $nonSemverRecords,
    );
    expect($loggedVersions)->toBe(['rolling']);

    // Every non-semver-skip log MUST carry `source` context.
    foreach ($nonSemverRecords as $record) {
        expect($record['level'])->toBe('debug');
        expect($record['context'])->toHaveKey('source');
        expect($record['context']['source'])
            ->toBe('https://api.github.com/repos/symfony/console/releases');
    }
});

it('preserves null published_at as null date and coerces null body to empty string', function (): void {
    // Synthetic single-release JSON that exercises both null branches at once
    // for an entry that DOES pass semver normalization.
    $body = json_encode([[
        'tag_name' => 'v2.0.0',
        'name' => 'v2.0.0',
        'body' => null,
        'published_at' => null,
        'draft' => false,
        'prerelease' => false,
    ]], JSON_THROW_ON_ERROR);

    $response = new RawResponse(
        body: $body,
        contentType: 'application/json',
        source: new Source(type: SourceTypes::GITHUB_RELEASES, url: 'https://api.github.com/repos/x/y/releases'),
    );

    $result = (new GitHubReleasesParser())->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    expect($result->entries)->toHaveCount(1);
    expect($result->entries[0]->version)->toBe('v2.0.0');
    expect($result->entries[0]->date)->toBeNull();
    expect($result->entries[0]->raw)->toBe('');
    expect($result->entries[0]->sections)->toHaveCount(1);
    // Section lines may be either [''] (from explode("\n", "")) or [] — both
    // are acceptable per the plan; explode("\n", "") returns ['']. Pin the
    // explode-based outcome here.
    expect($result->entries[0]->sections[0]->lines)->toBe(['']);
});

// ---------------------------------------------------------------------------
// Failure paths — top-level shape + JSON syntax + depth guard
// ---------------------------------------------------------------------------

it('throws ParseException when the decoded JSON is not a top-level array', function (): void {
    $response = new RawResponse(
        body: '{"body": "not an array"}',
        contentType: 'application/json',
        source: new Source(type: SourceTypes::GITHUB_RELEASES, url: 'https://api.github.com/repos/x/y/releases'),
    );

    $caught = null;
    try {
        (new GitHubReleasesParser())->parse($response, VersionRange::changes('0.0.0', '99.0.0'));
    } catch (ParseException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ParseException::class);
    if (! $caught instanceof ParseException) {
        return;
    }
    expect($caught->getMessage())->toContain('did not decode to an array');
});

it('throws ParseException wrapping JsonException on syntactically invalid JSON', function (): void {
    $response = new RawResponse(
        body: 'not valid json {',
        contentType: 'application/json',
        source: new Source(type: SourceTypes::GITHUB_RELEASES, url: 'https://api.github.com/repos/x/y/releases'),
    );

    $caught = null;
    try {
        (new GitHubReleasesParser())->parse($response, VersionRange::changes('0.0.0', '99.0.0'));
    } catch (ParseException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ParseException::class);
    if (! $caught instanceof ParseException) {
        return;
    }
    expect($caught->getMessage())->toContain('Invalid GitHub Releases JSON');
    expect($caught->getPrevious())->toBeInstanceOf(JsonException::class);
});

it('throws ParseException on JSON nesting exceeding the depth cap (DoS guard)', function (): void {
    // 20 nested array opens followed by 20 closes — well past the depth: 8 cap.
    $pathological = str_repeat('[', 20) . str_repeat(']', 20);

    $response = new RawResponse(
        body: $pathological,
        contentType: 'application/json',
        source: new Source(type: SourceTypes::GITHUB_RELEASES, url: 'https://api.github.com/repos/x/y/releases'),
    );

    $caught = null;
    try {
        (new GitHubReleasesParser())->parse($response, VersionRange::changes('0.0.0', '99.0.0'));
    } catch (ParseException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ParseException::class);
    if (! $caught instanceof ParseException) {
        return;
    }
    expect($caught->getMessage())->toContain('Invalid GitHub Releases JSON');

    $previous = $caught->getPrevious();
    expect($previous)->toBeInstanceOf(JsonException::class);
    if (! $previous instanceof JsonException) {
        return;
    }
    // PHP's json_decode reports depth-limit errors via the message
    // ('Maximum stack depth exceeded' on older PHPs, similar wording on 8.3+).
    expect(stripos($previous->getMessage(), 'depth'))->not->toBe(false);
});

// ---------------------------------------------------------------------------
// Empty array + success log + context purity
// ---------------------------------------------------------------------------

it('returns a Changelog with empty entries on a top-level empty array and emits a debug success event with count=0', function (): void {
    $logger = new ArrayLogger();
    $response = new RawResponse(
        body: '[]',
        contentType: 'application/json',
        source: new Source(type: SourceTypes::GITHUB_RELEASES, url: 'https://api.github.com/repos/x/y/releases'),
    );

    $result = (new GitHubReleasesParser($logger))
        ->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    expect($result)->toBeInstanceOf(Changelog::class);
    expect($result->entries)->toBe([]);

    $successRecords = array_values(array_filter(
        $logger->records,
        static fn (array $r): bool => str_contains($r['message'], 'Parsed'),
    ));
    expect($successRecords)->toHaveCount(1);
    expect($successRecords[0]['level'])->toBe('debug');
    expect($successRecords[0]['context'])->toHaveKey('count');
    expect($successRecords[0]['context']['count'])->toBe(0);
    expect($successRecords[0]['context'])->toHaveKey('source');
    expect($successRecords[0]['context']['source'])
        ->toBe('https://api.github.com/repos/x/y/releases');
});

it('emits the success log with the correct count on a non-empty parse', function (): void {
    $logger = new ArrayLogger();
    $response = loadGithubFixture('symfony-console-releases.json');

    $result = (new GitHubReleasesParser($logger))
        ->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    $successRecords = array_values(array_filter(
        $logger->records,
        static fn (array $r): bool => str_contains($r['message'], 'Parsed'),
    ));
    expect($successRecords)->toHaveCount(1);
    expect($successRecords[0]['level'])->toBe('debug');
    expect($successRecords[0]['context']['count'])->toBe(count($result->entries));
});

it('only emits log context keys from the allow-list {version, source, count}', function (): void {
    $logger = new ArrayLogger();
    $allowed = ['version', 'source', 'count'];

    // Exercise both code paths so EVERY logger record gets scanned:
    // (a) non-semver-skip path via releases-with-nulls.json (logs `version` + `source`).
    // (b) success path via symfony-console-releases.json (logs `count` + `source`).
    (new GitHubReleasesParser($logger))
        ->parse(loadGithubFixture('releases-with-nulls.json'), VersionRange::changes('0.0.0', '99.0.0'));
    (new GitHubReleasesParser($logger))
        ->parse(loadGithubFixture('symfony-console-releases.json'), VersionRange::changes('0.0.0', '99.0.0'));

    expect($logger->records)->not->toBeEmpty();
    foreach ($logger->records as $record) {
        foreach (array_keys($record['context']) as $key) {
            expect($allowed)->toContain((string) $key);
        }
    }
});

// ---------------------------------------------------------------------------
// tag_name ?? name precedence for marketing-style release titles
// ---------------------------------------------------------------------------

it('uses tag_name ?? name precedence so marketing-style names do not drop entries', function (): void {
    $logger = new ArrayLogger();
    $parser = new GitHubReleasesParser($logger);
    $response = loadGithubFixture('marketing-name-releases.json');

    $result = $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    // Both entries have semver tag_name ('3.6.1', '3.6.0') but non-semver name
    // ('Public release 3.6.1', 'Public release 3.6.0'). Under the correct
    // tag_name ?? name precedence, tag_name is read first and both entries parse.
    // Under the OLD name ?? tag_name precedence, both are dropped.
    expect($result->entries)->toHaveCount(2);
    expect($result->entries[0]->version)->toBe('3.6.1');
    expect($result->entries[1]->version)->toBe('3.6.0');
    expect($result->entries[0]->raw)->toBe('Bug fixes and minor improvements.');
    expect($result->entries[0]->sourceUrl)->toBe('https://api.github.com/repos/symfony/console/releases');

    // No non-semver-skip log records — tag_name is semver-valid, so neither
    // entry should be dropped.
    $skipRecords = array_filter(
        $logger->records,
        static fn (array $r): bool => $r['message'] === 'Skipping non-semver version',
    );
    expect(array_values($skipRecords))->toHaveCount(0);
});

// ---------------------------------------------------------------------------
// tag_name = '' (empty string) falls back to name
// ---------------------------------------------------------------------------

it('falls back to name when tag_name is an empty string', function (): void {
    $body = json_encode([[
        'tag_name' => '',
        'name' => '1.2.0',
        'body' => null,
        'published_at' => null,
        'draft' => false,
        'prerelease' => false,
    ]], JSON_THROW_ON_ERROR);

    $response = new RawResponse(
        body: $body,
        contentType: 'application/json',
        source: new Source(type: SourceTypes::GITHUB_RELEASES, url: 'https://api.github.com/repos/x/y/releases'),
    );

    $result = (new GitHubReleasesParser())->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    expect($result->entries)->toHaveCount(1);
    expect($result->entries[0]->version)->toBe('1.2.0');
});

// ---------------------------------------------------------------------------
// Both tag_name and name null/absent — structural silent-skip (no log)
// ---------------------------------------------------------------------------

it('silently skips a release entry where both tag_name and name are absent (no log emitted)', function (): void {
    $logger = new ArrayLogger();
    $body = json_encode([
        ['tag_name' => null, 'name' => null, 'body' => null, 'published_at' => null, 'draft' => false, 'prerelease' => false],
    ], JSON_THROW_ON_ERROR);

    $response = new RawResponse(
        body: $body,
        contentType: 'application/json',
        source: new Source(type: SourceTypes::GITHUB_RELEASES, url: 'https://api.github.com/repos/x/y/releases'),
    );

    $result = (new GitHubReleasesParser($logger))->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    expect($result->entries)->toBe([]);
    // No "Skipping non-semver version" log — this is a structural skip, not a semver-parse skip.
    $skipRecords = array_filter(
        $logger->records,
        static fn (array $r): bool => $r['message'] === 'Skipping non-semver version',
    );
    expect(array_values($skipRecords))->toBe([]);
});

// ---------------------------------------------------------------------------
// Per-entry html_url (l68 fix) — each ChangelogEntry carries its own release
// page URL, falling back to the response source URL only when html_url is
// absent / null / empty / non-string.
// ---------------------------------------------------------------------------

it('uses per-release html_url as the entry sourceUrl when provided', function (): void {
    $body = json_encode([[
        'tag_name' => 'v4.0.0',
        'name' => 'v4.0.0',
        'body' => 'ok',
        'published_at' => null,
        'draft' => false,
        'prerelease' => false,
        'html_url' => 'https://github.com/x/y/releases/tag/v4.0.0',
    ]], JSON_THROW_ON_ERROR);

    $response = new RawResponse(
        body: $body,
        contentType: 'application/json',
        source: new Source(
            type: SourceTypes::GITHUB_RELEASES,
            url: 'https://api.github.com/repos/x/y/releases?per_page=100&page=1',
        ),
    );

    $result = (new GitHubReleasesParser())->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    expect($result->entries)->toHaveCount(1);
    expect($result->entries[0]->sourceUrl)->toBe('https://github.com/x/y/releases/tag/v4.0.0');
});

it('falls back to response source URL when html_url is missing, null, non-string, or empty', function (mixed $htmlUrl): void {
    $release = [
        'tag_name' => 'v4.0.0',
        'name' => 'v4.0.0',
        'body' => 'ok',
        'published_at' => null,
        'draft' => false,
        'prerelease' => false,
    ];
    // Marker value 'OMIT' means: leave the html_url key absent entirely.
    if ($htmlUrl !== 'OMIT') {
        $release['html_url'] = $htmlUrl;
    }

    $body = json_encode([$release], JSON_THROW_ON_ERROR);

    $response = new RawResponse(
        body: $body,
        contentType: 'application/json',
        source: new Source(
            type: SourceTypes::GITHUB_RELEASES,
            url: 'https://api.github.com/repos/x/y/releases',
        ),
    );

    $result = (new GitHubReleasesParser())->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    expect($result->entries)->toHaveCount(1);
    expect($result->entries[0]->sourceUrl)->toBe('https://api.github.com/repos/x/y/releases');
})->with([
    'html_url key absent' => 'OMIT',
    'html_url is null' => null,
    'html_url is empty string' => '',
    'html_url is non-string (int)' => 42,
]);
