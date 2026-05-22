<?php

declare(strict_types=1);

use DateTimeImmutable;
use JsonException;
use LogicException;
use n5s\Rangelog\Domain\RawResponse;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Domain\SourceTypes;
use n5s\Rangelog\Domain\VersionRange;
use n5s\Rangelog\Exception\ParseException;
use n5s\Rangelog\Parser\ChangelogParserInterface;
use n5s\Rangelog\Parser\GitLabReleasesParser;
use n5s\Rangelog\Parser\MarkdownParser;
use n5s\Rangelog\Parser\ParserRegistry;
use n5s\Rangelog\Tests\TestSupport\ArrayLogger;
use ReflectionClass;

/**
 * Load a GitLab Releases fixture into a buffered RawResponse. Mirrors
 * loadGithubFixture — file-scope helper, throws LogicException if the
 * fixture is missing so the test fails loudly rather than silently skipping.
 */
function loadGitlabFixture(
    string $relPath,
    string $url = 'https://gitlab.com/api/v4/projects/gitlab-org%2Frelease-cli/releases?per_page=100&page=1&order_by=released_at&sort=desc',
): RawResponse {
    $abs = __DIR__ . '/../../Fixtures/gitlab/' . $relPath;
    if (! is_file($abs)) {
        throw new LogicException("Missing GitLab fixture: {$abs}");
    }
    $body = file_get_contents($abs);
    if ($body === false) {
        throw new LogicException("Cannot read GitLab fixture: {$abs}");
    }

    return new RawResponse(
        body: $body,
        contentType: 'application/json',
        source: new Source(type: SourceTypes::GITLAB_RELEASES, url: $url),
    );
}

// ---------------------------------------------------------------------------
// Test 1 — Structure
// ---------------------------------------------------------------------------

it('is a final class implementing ChangelogParserInterface', function (): void {
    $reflection = new ReflectionClass(GitLabReleasesParser::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->implementsInterface(ChangelogParserInterface::class))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Test 2 — Constructor smoke
// ---------------------------------------------------------------------------

it('constructs without arguments (NullLogger default) and accepts an optional LoggerInterface', function (): void {
    $defaultParser = new GitLabReleasesParser();
    expect($defaultParser)->toBeInstanceOf(GitLabReleasesParser::class);

    $loggerParser = new GitLabReleasesParser(new ArrayLogger());
    expect($loggerParser)->toBeInstanceOf(GitLabReleasesParser::class);
});

// ---------------------------------------------------------------------------
// Test 3 — Happy-path: entry count from release-cli-page1.json
// ---------------------------------------------------------------------------

it('parses release-cli-page1.json into a Changelog with the expected entry count', function (): void {
    $parser = new GitLabReleasesParser();
    $response = loadGitlabFixture('releases/release-cli-page1.json');

    $fixtureData = json_decode((string) file_get_contents(
        __DIR__ . '/../../Fixtures/gitlab/releases/release-cli-page1.json',
    ), true, 8, JSON_THROW_ON_ERROR);
    if (! is_array($fixtureData)) {
        throw new LogicException('release-cli-page1.json fixture must decode to a JSON array');
    }

    $result = $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    expect($result->entries)->toHaveCount(count($fixtureData));
});

// ---------------------------------------------------------------------------
// Test 4 — field mapping: tag_name → version, released_at → date,
//          description → raw, _links.self → sourceUrl
// ---------------------------------------------------------------------------

it('maps tag_name to version, released_at to date, description to raw, and _links.self to sourceUrl', function (): void {
    $parser = new GitLabReleasesParser();
    $response = loadGitlabFixture('releases/release-cli-page1.json');

    $result = $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    $entry = $result->entries[0];

    expect($entry->version)->toBe('v0.16.0');
    expect($entry->date)->toBeInstanceOf(DateTimeImmutable::class);
    expect($entry->raw)->toBe("## Changes\n- Add support for multi-project pipelines\n- Fix authentication token refresh\n- Update dependency versions");
    expect($entry->sourceUrl)->toBe('https://gitlab.com/gitlab-org/release-cli/-/releases/v0.16.0');
});

// ---------------------------------------------------------------------------
// Test 5 — One ChangelogSection per entry with empty title + explode lines
// ---------------------------------------------------------------------------

it('emits exactly one ChangelogSection per entry with empty title and lines = explode("\\n", description)', function (): void {
    $parser = new GitLabReleasesParser();
    $response = loadGitlabFixture('releases/release-cli-page1.json');

    $result = $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    $entry = $result->entries[0];

    expect($entry->sections)->toHaveCount(1);
    expect($entry->sections[0]->title)->toBe('');
    expect($entry->sections[0]->lines)->toBe(explode("\n", $entry->raw));
});

// ---------------------------------------------------------------------------
// Test 6 — tag_name ?? name precedence for GitLab
// ---------------------------------------------------------------------------

it('uses tag_name ?? name precedence so marketing-style names do not drop entries', function (): void {
    $parser = new GitLabReleasesParser();
    $response = loadGitlabFixture('releases/marketing-name.json');

    $result = $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    expect($result->entries)->toHaveCount(1);
    expect($result->entries[0]->version)->toBe('v3.6.1');
});

// ---------------------------------------------------------------------------
// Test 7 — non-semver silent skip
// ---------------------------------------------------------------------------

it('silently skips an entry whose selected raw version (tag_name first per precedence) fails VersionParser::normalize', function (): void {
    $logger = new ArrayLogger();
    $parser = new GitLabReleasesParser($logger);
    $response = loadGitlabFixture('releases/non-semver-tag.json');

    // PRECEDENCE NOTE — the parser's tag_name ?? name precedence operates BEFORE normalize, not as a
    // normalize-failure fallback. Reading GitHubReleasesParser lines 147-156 (the analog):
    //
    //   $tagField = $release['tag_name'] ?? null;
    //   if (is_string($tagField) && $tagField !== '') {
    //       $rawVersion = $tagField;   // tag_name wins when present-string-non-empty
    //   } else {
    //       $nameField = $release['name'] ?? null;
    //       if (! is_string($nameField) || $nameField === '') { continue; }
    //       $rawVersion = $nameField;  // fallback ONLY when tag_name is absent/empty
    //   }
    //
    //   try { $versionParser->normalize($rawVersion); }
    //   catch (UnexpectedValueException) { /* skip + log */ continue; }
    //
    // So if tag_name is present-string-non-empty (even non-semver), the parser uses it. If tag_name
    // is then non-semver, the entry is silently skipped and `name` is NEVER consulted as a fallback
    // for the normalize failure. In THIS fixture both tag_name AND name are non-semver, so the entry
    // is silently skipped regardless of which field is picked. The assertion pins
    // version='2026-04-18-snapshot' in the debug log context because tag_name takes precedence in
    // the tag_name ?? name lookup — this is the $rawVersion value the parser feeds into
    // VersionParser::normalize() before the failure-and-skip path fires.

    $result = $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    expect($result->entries)->toHaveCount(0);

    $skipRecords = array_values(array_filter(
        $logger->records,
        static fn (array $r): bool => $r['message'] === 'Skipping non-semver version',
    ));
    expect($skipRecords)->toHaveCount(1);
    expect($skipRecords[0]['level'])->toBe('debug');
    expect($skipRecords[0]['context']['version'])->toBe('2026-04-18-snapshot');
});

// ---------------------------------------------------------------------------
// Test 8 — schema tolerance (assets/milestones/evidences/commit/author)
// ---------------------------------------------------------------------------

it('ignores assets / milestones / evidences / commit / author fields without crashing', function (): void {
    $parser = new GitLabReleasesParser();
    $response = loadGitlabFixture('releases/release-cli-page1.json');

    $fixtureData = json_decode((string) file_get_contents(
        __DIR__ . '/../../Fixtures/gitlab/releases/release-cli-page1.json',
    ), true, 8, JSON_THROW_ON_ERROR);
    if (! is_array($fixtureData)) {
        throw new LogicException('release-cli-page1.json fixture must decode to a JSON array');
    }

    $result = $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    // Parser must not crash on presence of extra fields and must not skip entries
    expect($result->entries)->toHaveCount(count($fixtureData));
});

// ---------------------------------------------------------------------------
// Test 9 — sourceUrl is null when _links is absent or non-array
// ---------------------------------------------------------------------------

it('sets sourceUrl to null when _links is absent or non-array', function (): void {
    $sourceUrl = 'https://gitlab.com/api/v4/projects/gitlab-org%2Frelease-cli/releases?per_page=100&page=1&order_by=released_at&sort=desc';

    // The fallback is null. The synthetic release below has no `_links.self`, so the
    // parser must emit `sourceUrl: null` — not the Releases API endpoint URL (which
    // would be the wrong resource for a caller checking `sourceUrl !== null` to obtain
    // a human-readable release page).
    $body = json_encode([[
        'tag_name' => 'v1.0.0',
        'name' => 'v1.0.0',
        'released_at' => '2026-01-01T00:00:00.000Z',
        'description' => 'Initial release',
        // no _links field
    ]], JSON_THROW_ON_ERROR);

    $response = new RawResponse(
        body: $body,
        contentType: 'application/json',
        source: new Source(type: SourceTypes::GITLAB_RELEASES, url: $sourceUrl),
    );

    $result = (new GitLabReleasesParser())->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    expect($result->entries)->toHaveCount(1);
    expect($result->entries[0]->sourceUrl)->toBeNull();
});

// ---------------------------------------------------------------------------
// Test 10 — fractional-seconds date format via DateTimeImmutable constructor
// ---------------------------------------------------------------------------

it('parses released_at fractional-seconds format "2026-04-18T12:00:00.000Z" via DateTimeImmutable constructor', function (): void {
    $parser = new GitLabReleasesParser();
    $response = loadGitlabFixture('releases/marketing-name.json');

    $result = $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    expect($result->entries)->toHaveCount(1);
    expect($result->entries[0]->date)->toBeInstanceOf(DateTimeImmutable::class);
    expect($result->entries[0]->date?->format('Y-m-d H:i:s'))->toBe('2026-04-18 12:00:00');
});

// ---------------------------------------------------------------------------
// Test 11 — logger never logs description/body/raw
// ---------------------------------------------------------------------------

it('never logs the description field content (logger allow-list)', function (): void {
    $logger = new ArrayLogger();
    $parser = new GitLabReleasesParser($logger);
    $response = loadGitlabFixture('releases/release-cli-page1.json');

    $parser->parse($response, VersionRange::changes('0.0.0', '99.0.0'));

    $allowed = ['version', 'source', 'count'];

    expect($logger->records)->not->toBeEmpty();
    foreach ($logger->records as $record) {
        foreach (array_keys($record['context']) as $key) {
            expect($allowed)->toContain((string) $key);
        }
    }
});

// ---------------------------------------------------------------------------
// Test 12 — ParserRegistry binding smoke: GITLAB_RELEASES => GitLabReleasesParser
// (canonical assertion lives in ParserRegistryDefaultsTest.php)
// ---------------------------------------------------------------------------

it('ParserRegistry::defaults() registers SourceTypes::GITLAB_RELEASES => GitLabReleasesParser (smoke — canonical assertion lives in ParserRegistryDefaultsTest.php)', function (): void {
    $reg = ParserRegistry::defaults();
    $parser = $reg->parserFor(SourceTypes::GITLAB_RELEASES);

    expect($parser)->toBeInstanceOf(GitLabReleasesParser::class);
});

// ---------------------------------------------------------------------------
// Test 13 — ParserRegistry binding smoke: GITLAB_FILE => MarkdownParser (independent instance)
// (canonical assertion lives in ParserRegistryDefaultsTest.php)
// ---------------------------------------------------------------------------

it('ParserRegistry::defaults() registers SourceTypes::GITLAB_FILE => MarkdownParser as an independent instance (smoke — canonical assertion lives in ParserRegistryDefaultsTest.php)', function (): void {
    $reg = ParserRegistry::defaults();
    $parser = $reg->parserFor(SourceTypes::GITLAB_FILE);

    expect($parser)->toBeInstanceOf(MarkdownParser::class);
    // Pointer inequality — GITLAB_FILE and GITHUB_FILE each get their own MarkdownParser
    // instance.
    expect($parser)->not->toBe($reg->parserFor(SourceTypes::GITHUB_FILE));
});

// ---------------------------------------------------------------------------
// Test 14 — ParseException on non-array-root JSON (mirrors GitHubReleasesParserTest)
// ---------------------------------------------------------------------------

it('throws ParseException when the decoded JSON is not a top-level array', function (): void {
    $response = new RawResponse(
        body: '{"body": "not an array"}',
        contentType: 'application/json',
        source: new Source(
            type: SourceTypes::GITLAB_RELEASES,
            url: 'https://gitlab.com/api/v4/projects/gitlab-org%2Frelease-cli/releases?per_page=100&page=1&order_by=released_at&sort=desc',
        ),
    );

    $caught = null;
    try {
        (new GitLabReleasesParser())->parse($response, VersionRange::changes('0.0.0', '99.0.0'));
    } catch (ParseException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ParseException::class);
    if (! $caught instanceof ParseException) {
        return;
    }
    expect($caught->getMessage())->toContain('did not decode to an array');
});

// ---------------------------------------------------------------------------
// Test 15 — ParseException wrapping JsonException on syntactically invalid JSON
// ---------------------------------------------------------------------------

it('throws ParseException wrapping JsonException on syntactically invalid JSON', function (): void {
    $response = new RawResponse(
        body: 'not valid json {',
        contentType: 'application/json',
        source: new Source(
            type: SourceTypes::GITLAB_RELEASES,
            url: 'https://gitlab.com/api/v4/projects/gitlab-org%2Frelease-cli/releases?per_page=100&page=1&order_by=released_at&sort=desc',
        ),
    );

    $caught = null;
    try {
        (new GitLabReleasesParser())->parse($response, VersionRange::changes('0.0.0', '99.0.0'));
    } catch (ParseException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ParseException::class);
    if (! $caught instanceof ParseException) {
        return;
    }
    expect($caught->getMessage())->toContain('Invalid GitLab Releases JSON');
    expect($caught->getPrevious())->toBeInstanceOf(JsonException::class);
});
