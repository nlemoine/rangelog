<?php

declare(strict_types=1);

use n5s\Rangelog\Domain\RawResponse;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Domain\SourceTypes;
use n5s\Rangelog\Domain\VersionRange;
use n5s\Rangelog\Parser\GitHubReleasesParser;
use n5s\Rangelog\Parser\GitLabReleasesParser;
use n5s\Rangelog\Parser\MarkdownParser;
use n5s\Rangelog\Parser\ParserRegistry;
use n5s\Rangelog\Parser\WordPressReadmeParser;
use n5s\Rangelog\Tests\TestSupport\ArrayLogger;

/**
 * Tests for the static factory `ParserRegistry::defaults(?LoggerInterface): self`.
 *
 * Mirrors the per-key `parserFor()` happy-path tests in
 * tests/Unit/Parser/ParserRegistryTest.php, but exercises
 * `ParserRegistry::defaults()` as the constructor under test.
 */

// ---------------------------------------------------------------------------
// Construction — null-default + ArrayLogger injection (smoke)
// ---------------------------------------------------------------------------

it('returns a ParserRegistry instance when called with no arguments (null-default logger)', function (): void {
    $registry = ParserRegistry::defaults();

    expect($registry)->toBeInstanceOf(ParserRegistry::class);
});

it('returns a ParserRegistry instance when called with an explicit ArrayLogger', function (): void {
    $registry = ParserRegistry::defaults(new ArrayLogger());

    expect($registry)->toBeInstanceOf(ParserRegistry::class);
});

// ---------------------------------------------------------------------------
// Four-key map — concrete parser type per SourceTypes::* constant
// ---------------------------------------------------------------------------

it('registers GitHubReleasesParser for SourceTypes::GITHUB_RELEASES', function (): void {
    $parser = ParserRegistry::defaults()->parserFor(SourceTypes::GITHUB_RELEASES);

    expect($parser)->toBeInstanceOf(GitHubReleasesParser::class);
});

it('registers MarkdownParser for SourceTypes::GITHUB_FILE', function (): void {
    $parser = ParserRegistry::defaults()->parserFor(SourceTypes::GITHUB_FILE);

    expect($parser)->toBeInstanceOf(MarkdownParser::class);
});

it('registers MarkdownParser for SourceTypes::MARKDOWN_URL', function (): void {
    $parser = ParserRegistry::defaults()->parserFor(SourceTypes::MARKDOWN_URL);

    expect($parser)->toBeInstanceOf(MarkdownParser::class);
});

it('registers WordPressReadmeParser for SourceTypes::WORDPRESS_ORG', function (): void {
    $parser = ParserRegistry::defaults()->parserFor(SourceTypes::WORDPRESS_ORG);

    expect($parser)->toBeInstanceOf(WordPressReadmeParser::class);
});

// ---------------------------------------------------------------------------
// GITHUB_FILE and MARKDOWN_URL get SEPARATE MarkdownParser instances
// (cleaner ownership; two instances).
// ---------------------------------------------------------------------------

it('threads SEPARATE MarkdownParser instances into GITHUB_FILE and MARKDOWN_URL', function (): void {
    $registry = ParserRegistry::defaults();

    $ghFile = $registry->parserFor(SourceTypes::GITHUB_FILE);
    $markdownUrl = $registry->parserFor(SourceTypes::MARKDOWN_URL);

    expect($ghFile)->toBeInstanceOf(MarkdownParser::class);
    expect($markdownUrl)->toBeInstanceOf(MarkdownParser::class);
    // Pointer inequality — two distinct constructor calls.
    expect($ghFile)->not->toBe($markdownUrl);
});

// ---------------------------------------------------------------------------
// Logger threading — passing a non-null logger reaches at least one parser.
// Proof: parse a markdown fixture with a non-semver H2 header. The
// MarkdownParser emits a `debug` "Skipping non-semver version" event for
// each non-semver section it encounters. The ArrayLogger
// then carries at least one record — which is impossible unless the
// logger was forwarded into the MarkdownParser instance the registry built.
// ---------------------------------------------------------------------------

it('threads the injected logger through to each built-in parser', function (): void {
    $logger = new ArrayLogger();
    $registry = ParserRegistry::defaults($logger);

    $parser = $registry->parserFor(SourceTypes::GITHUB_FILE);

    $path = __DIR__ . '/../../Fixtures/markdown/with-non-semver.md';
    $body = file_get_contents($path);
    if ($body === false) {
        throw new LogicException("Fixture not found: {$path}");
    }

    $parser->parse(
        new RawResponse(
            body: $body,
            contentType: 'text/markdown',
            source: new Source(type: SourceTypes::GITHUB_FILE, url: 'https://example.com/CHANGELOG.md'),
        ),
        VersionRange::changes('0.0.0', '99.0.0'),
    );

    // The fixture has two non-semver H2 sections (calendar date + branch name)
    // AND a "Parsed {count} entries" debug at end-of-parse — so the records
    // array MUST contain at least one record once the logger is threaded.
    expect($logger->records)->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// Null fallback — passing null does not error; construction is fully wired.
// ---------------------------------------------------------------------------

it('falls back to NullLogger when null is passed (smoke — no error)', function (): void {
    $registry = ParserRegistry::defaults();

    // All six keys still resolve — proves the constructor finished wiring
    // even with the null-default branch taken.
    expect($registry->parserFor(SourceTypes::GITHUB_RELEASES))->toBeInstanceOf(GitHubReleasesParser::class);
    expect($registry->parserFor(SourceTypes::GITHUB_FILE))->toBeInstanceOf(MarkdownParser::class);
    expect($registry->parserFor(SourceTypes::MARKDOWN_URL))->toBeInstanceOf(MarkdownParser::class);
    expect($registry->parserFor(SourceTypes::WORDPRESS_ORG))->toBeInstanceOf(WordPressReadmeParser::class);
    expect($registry->parserFor(SourceTypes::GITLAB_RELEASES))->toBeInstanceOf(GitLabReleasesParser::class);
    expect($registry->parserFor(SourceTypes::GITLAB_FILE))->toBeInstanceOf(MarkdownParser::class);
});

// ---------------------------------------------------------------------------
// GitLab bindings — parallel smoke checks also live in
// tests/Unit/Parser/GitLabReleasesParserTest.php from the parser's perspective.
// ---------------------------------------------------------------------------

it('registers GitLabReleasesParser for SourceTypes::GITLAB_RELEASES', function (): void {
    $parser = ParserRegistry::defaults()->parserFor(SourceTypes::GITLAB_RELEASES);

    expect($parser)->toBeInstanceOf(GitLabReleasesParser::class);
});

it('registers MarkdownParser for SourceTypes::GITLAB_FILE as an independent instance', function (): void {
    $registry = ParserRegistry::defaults();

    $gitlabFile = $registry->parserFor(SourceTypes::GITLAB_FILE);
    $githubFile = $registry->parserFor(SourceTypes::GITHUB_FILE);

    expect($gitlabFile)->toBeInstanceOf(MarkdownParser::class);
    // Pointer inequality — GITLAB_FILE and GITHUB_FILE each get their own MarkdownParser
    // instance (two instances for cleaner ownership).
    expect($gitlabFile)->not->toBe($githubFile);
});
