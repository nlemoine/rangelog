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
use n5s\Rangelog\Parser\GitHubReleasesParser;
use n5s\Rangelog\Parser\MarkdownParser;
use n5s\Rangelog\Parser\ParserRegistry;
use n5s\Rangelog\Parser\WordPressReadmeParser;

/**
 * Build an in-test ChangelogParserInterface implementor carrying a marker
 * string — the marker survives into the returned Changelog so tests can
 * assert pointer identity AND prove which stub was returned.
 *
 * Pattern mirrors tests/Unit/Fetcher/FetcherStackTest.php lines 14-26
 * (anonymous-class-implements-interface — PHPStan-clean, no static-analysis
 * suppression annotations needed).
 */
function stubParser(string $marker): ChangelogParserInterface
{
    return new readonly class ($marker) implements ChangelogParserInterface {
        public function __construct(private string $marker)
        {
        }

        public function parse(RawResponse $response, VersionRange $range): Changelog
        {
            return new Changelog([
                new ChangelogEntry(
                    version: '1.0.0',
                    date: null,
                    sections: [new ChangelogSection(title: '', lines: [$this->marker])],
                    raw: $this->marker,
                ),
            ]);
        }
    };
}

/**
 * Load a markdown fixture for the registry's integration-smoke test.
 *
 * Slightly different name from the per-parser test files' helper
 * (`loadMarkdownFixture`) to avoid Pest's flat function-registry
 * collisions: tests have no namespace and helpers are global.
 */
function loadMarkdownFixtureForRegistry(string $relPath, string $url = 'https://example.com/CHANGELOG.md'): RawResponse
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
// Structure (registry is a FACTORY, not a parser)
// ---------------------------------------------------------------------------

it('is a final class', function (): void {
    $reflection = new ReflectionClass(ParserRegistry::class);

    expect($reflection->isFinal())->toBeTrue();
});

it('does NOT itself satisfy the parser contract — it is a dispatcher, not a parser', function (): void {
    // Contrast: FetcherStack IS a FetcherInterface because it composes one and
    // exposes fetch(). ParserRegistry returns a parser but does NOT expose
    // parse().
    $reflection = new ReflectionClass(ParserRegistry::class);

    expect($reflection->implementsInterface(ChangelogParserInterface::class))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Happy path: lookup by SourceTypes constants
// ---------------------------------------------------------------------------

it('returns the registered parser for SourceTypes::GITHUB_FILE', function (): void {
    $stub = stubParser('GH_FILE');
    $registry = new ParserRegistry([SourceTypes::GITHUB_FILE => $stub]);

    expect($registry->parserFor(SourceTypes::GITHUB_FILE))->toBeInstanceOf(ChangelogParserInterface::class);
});

it('returns the registered parser for SourceTypes::GITHUB_RELEASES', function (): void {
    $stub = stubParser('GH_REL');
    $registry = new ParserRegistry([SourceTypes::GITHUB_RELEASES => $stub]);

    expect($registry->parserFor(SourceTypes::GITHUB_RELEASES))->toBeInstanceOf(ChangelogParserInterface::class);
});

it('returns the registered parser for SourceTypes::WORDPRESS_ORG', function (): void {
    $stub = stubParser('WP');
    $registry = new ParserRegistry([SourceTypes::WORDPRESS_ORG => $stub]);

    expect($registry->parserFor(SourceTypes::WORDPRESS_ORG))->toBeInstanceOf(ChangelogParserInterface::class);
});

it('returns the registered parser for SourceTypes::MARKDOWN_URL (BYO — registry accepts any key)', function (): void {
    // The registry must accept any string discriminator per the BYO design.
    // MARKDOWN_URL is the successor to COMPOSER_SUPPORT.
    $stub = stubParser('MARKDOWN_URL');
    $registry = new ParserRegistry([SourceTypes::MARKDOWN_URL => $stub]);

    expect($registry->parserFor(SourceTypes::MARKDOWN_URL))->toBeInstanceOf(ChangelogParserInterface::class);
});

it('returns the EXACT registered instance (pointer equality, not a clone)', function (): void {
    $stub = stubParser('IDENTITY');
    $registry = new ParserRegistry([SourceTypes::GITHUB_FILE => $stub]);

    expect($registry->parserFor(SourceTypes::GITHUB_FILE))->toBe($stub);
});

// ---------------------------------------------------------------------------
// Miss path: ParseException on unregistered type
// ---------------------------------------------------------------------------

it('throws ParseException when the type is not registered, with the unknown type in the message', function (): void {
    $registry = new ParserRegistry([SourceTypes::GITHUB_FILE => stubParser('GH_FILE')]);

    $caught = null;
    try {
        $registry->parserFor('unknown_type');
    } catch (ParseException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught?->getMessage())->toContain('unknown_type');
});

it('throws ParseException on an empty-string key', function (): void {
    $registry = new ParserRegistry([SourceTypes::GITHUB_FILE => stubParser('GH_FILE')]);

    $caught = null;
    try {
        $registry->parserFor('');
    } catch (ParseException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught)->toBeInstanceOf(ParseException::class);
});

it('accepts an empty map and every parserFor() lookup throws ParseException (well-defined empty state)', function (): void {
    $registry = new ParserRegistry([]);

    $caught = null;
    try {
        $registry->parserFor(SourceTypes::GITHUB_FILE);
    } catch (ParseException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught?->getMessage())->toContain(SourceTypes::GITHUB_FILE);
});

// ---------------------------------------------------------------------------
// Integration smoke: composition with the three REAL parsers proves Liskov
// substitution and end-to-end wiring through the dispatcher.
// ---------------------------------------------------------------------------

it('dispatches to the real MarkdownParser, which parses kac-descending.md into a non-empty Changelog (integration smoke)', function (): void {
    $registry = new ParserRegistry([
        SourceTypes::GITHUB_FILE     => new MarkdownParser(),
        SourceTypes::GITHUB_RELEASES => new GitHubReleasesParser(),
        SourceTypes::WORDPRESS_ORG   => new WordPressReadmeParser(),
    ]);

    $parser = $registry->parserFor(SourceTypes::GITHUB_FILE);
    expect($parser)->toBeInstanceOf(MarkdownParser::class);

    $result = $parser->parse(
        loadMarkdownFixtureForRegistry('kac-descending.md'),
        VersionRange::changes('0.0.0', '99.0.0'),
    );

    expect($result)->toBeInstanceOf(Changelog::class);
    expect($result->entries)->not->toBeEmpty();
});
