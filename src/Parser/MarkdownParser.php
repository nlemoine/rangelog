<?php

declare(strict_types=1);

namespace n5s\Rangelog\Parser;

use Composer\Semver\VersionParser;
use DateTimeImmutable;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Parser\MarkdownParser as CommonMarkParser;
use n5s\Rangelog\Domain\Changelog;
use n5s\Rangelog\Domain\ChangelogEntry;
use n5s\Rangelog\Domain\ChangelogSection;
use n5s\Rangelog\Domain\RawResponse;
use n5s\Rangelog\Domain\VersionRange;
use n5s\Rangelog\Exception\ParseException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use UnexpectedValueException;

/**
 * Keep-a-Changelog-style CHANGELOG.md parser via league/commonmark AST walk.
 *
 * Recognises every shape this library supports:
 *   ## [1.2.3]
 *   ## 1.2.3
 *   ## v1.2.3
 *   ## [1.2.3] - 2026-01-15
 *   ## Version 1.2.3
 *   ## 27.5            (Yoast bare form — date in `Release date:` body line)
 *   Version 1.2.3      (setext H1 with === underline)
 *   =============
 *
 * Every entry carries exactly one ChangelogSection with an empty title. The
 * verbatim source between consecutive version headers is captured into the
 * section's lines and into ChangelogEntry::$raw.
 *
 * The constructor accepts an optional LoggerInterface. All emitted events
 * are at `debug` level: "Skipping Unreleased section", "Skipping non-semver
 * version", "Parsed {count} entries from {source}".
 *
 * The parser does NOT filter by VersionRange — it returns the full
 * Changelog and {@see \n5s\Rangelog\Domain\Changelog::filter()} is the
 * authoritative range boundary.
 *
 * Security:
 *  - Untrusted markdown: the `raw` field carries content verbatim. Callers
 *    rendering to HTML MUST sanitize first. The bundled MarkdownRenderer
 *    is the layer responsible for sanitisation.
 *  - Regex DoS: all version-header regexes are anchored at `^...$` with
 *    bounded character classes; no unbounded `.*` between groups. Declared
 *    as typed `private const string` so phpstan-strict-rules can validate
 *    them statically.
 *  - Logger leakage: context keys are restricted to `version`, `source`,
 *    `count` — no PSR-7 messages, no full bodies, no headers.
 *
 * @warning Untrusted content — caller must sanitize before HTML rendering.
 */
final readonly class MarkdownParser implements ChangelogParserInterface
{
    /** Skip-list — these are NEVER version entries. */
    private const string UNRELEASED = '/^\s*\[?unreleased\]?\s*$/i';

    /**
     * Master version-header pattern — anchored, bounded character classes
     * (regex-DoS mitigation).
     *
     * Group 1 = cleaned version string (no v-prefix, no brackets)
     * Group 2 = optional ISO date (YYYY-MM-DD) when carried inline.
     */
    private const string VERSION_HEADER = '~^
        \s*\[?
        (?:version\s+)?                        # optional "Version " literal
        v?                                     # optional v prefix
        ([\d]+(?:\.\d+){1,3}                   # 2-4 component numeric version
            (?:[-+][\w.\-+]+)?                 # optional pre-release or build metadata
        )
        \]?                                    # optional closing bracket
        \s*
        (?:[-\x{2013}(\s]\s*                   # delimiter: dash, en-dash, paren, or whitespace
            (\d{4}-\d{2}-\d{2})                # ISO date
            [)\s]*
        )?
        \s*$
    ~xiu';

    /** Date metadata fallback for Yoast-style bare-version entries. */
    private const string RELEASE_DATE_META = '/^Release\s+date:\s*(\d{4}-\d{2}-\d{2})\s*$/im';

    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function parse(RawResponse $response, VersionRange $range): Changelog
    {
        $body = $response->body;
        $source = $response->source->url;

        if (trim($body) === '') {
            throw new ParseException('No version headers found in markdown source: empty body');
        }

        $environment = new Environment([]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());

        $parser = new CommonMarkParser($environment);
        $document = $parser->parse($body);

        // Split the source into lines once — used for verbatim body slicing
        // between consecutive version-header line positions.
        $bodyLines = preg_split('/\r\n|\n|\r/', $body);
        if ($bodyLines === false) {
            // preg_split should never fail on a static pattern; treat as defensive guard.
            throw new ParseException('Unable to split markdown body into lines');
        }

        /**
         * Collected blocks pre-validation.
         *
         * @var list<array{version: string, date: ?DateTimeImmutable, start_line: int, end_line: int}> $rawBlocks
         */
        $rawBlocks = [];
        $unreleasedSkips = 0;

        foreach ($document->iterator() as $node) {
            if (! $node instanceof Heading) {
                continue;
            }

            $level = $node->getLevel();
            // H2 ATX (`##`) is the canonical version-marker level; setext H1 (`Version\n====`)
            // also maps to a level-1 Heading and is treated as version-candidate ONLY when the
            // VERSION_HEADER regex matches. H3+ are subsections / prose — never versions.
            if ($level > 2) {
                continue;
            }

            $text = $this->flattenHeadingText($node);

            if (preg_match(self::UNRELEASED, $text) === 1) {
                ++$unreleasedSkips;
                continue;
            }

            $matched = preg_match(self::VERSION_HEADER, $text, $matches) === 1;

            if (! $matched) {
                if ($level === 1) {
                    // H1 without a version shape is a document title (`# Changelog`,
                    // setext `Yoast SEO\n====`) — prose.
                    continue;
                }

                // H2 heading that didn't match the canonical version pattern is a
                // non-semver dialect identifier (e.g. `## 2026-04-28` calendar-versions
                // or `## main-branch` rolling-build names). Log at debug.
                $this->logger->debug(
                    'Skipping non-semver version',
                    ['version' => $text, 'source' => $source],
                );
                continue;
            }

            $startLine = $node->getStartLine();
            if ($startLine === null) {
                // CommonMark always populates start lines for block nodes — defensive guard for PHPStan max.
                continue;
            }

            // Capture the heading's LAST source line. For ATX
            // headings getEndLine() equals getStartLine() (single-line span).
            // For setext headings getEndLine() returns the underline line
            // (`=========` / `---------`), one line below the text line —
            // the body slice MUST start AFTER this line so the underline
            // never leaks into ChangelogEntry::$raw.
            $endLine = $node->getEndLine() ?? $startLine;

            $version = $matches[1];
            $date = isset($matches[2]) ? $this->parseIsoDate($matches[2]) : null;

            $rawBlocks[] = [
                'version' => $version,
                'date' => $date,
                'start_line' => $startLine,
                'end_line' => $endLine,
            ];
        }

        for ($i = 0; $i < $unreleasedSkips; ++$i) {
            $this->logger->debug('Skipping Unreleased section', []);
        }

        if ($rawBlocks === []) {
            throw new ParseException('No version headers found in markdown source');
        }

        $totalLines = \count($bodyLines);
        $entries = [];

        foreach ($rawBlocks as $index => $block) {
            $bodyStartLine = $block['end_line']; // last source line of the heading (1-based)
            $bodyEndLine = $rawBlocks[$index + 1]['start_line'] ?? ($totalLines + 1);

            // Slice the source lines BETWEEN the heading's last line and the next heading line.
            // preg_split produces a 0-indexed array; CommonMark line numbers are 1-based.
            // For ATX headings end_line == start_line so behaviour is unchanged.
            // For setext headings end_line is the underline line; offset $bodyStartLine
            // (i.e. 0-indexed array position end_line, which is line end_line+1 in 1-based
            // terms — the line AFTER the underline) correctly skips both heading text
            // AND the underline.
            $blockLines = \array_slice(
                $bodyLines,
                $bodyStartLine, // skip the heading's LAST source line — body starts at the next line
                max(0, $bodyEndLine - $bodyStartLine - 1),
            );

            $date = $block['date'];
            if ($date === null) {
                // Scan the first ~3 paragraphs for "Release date: YYYY-MM-DD".
                $headSlice = implode("\n", \array_slice($blockLines, 0, 10));
                if (preg_match(self::RELEASE_DATE_META, $headSlice, $metaMatches) === 1) {
                    $date = $this->parseIsoDate($metaMatches[1]);
                }
            }

            try {
                (new VersionParser())->normalize($block['version']);
            } catch (UnexpectedValueException) {
                $this->logger->debug(
                    'Skipping non-semver version',
                    ['version' => $block['version'], 'source' => $source],
                );
                continue;
            }

            $raw = implode("\n", $blockLines);

            $entries[] = new ChangelogEntry(
                version: $block['version'],
                date: $date,
                sections: [new ChangelogSection(title: '', lines: $blockLines)],
                raw: $raw,
                sourceUrl: $source,
            );
        }

        $this->logger->debug(
            'Parsed {count} entries from {source}',
            ['count' => \count($entries), 'source' => $source],
        );

        return new Changelog($entries);
    }

    /**
     * Parse a YYYY-MM-DD date string into a DateTimeImmutable, collapsing
     * `false` to `null` for the `ChangelogEntry::$date` field's ?nullable type.
     *
     * PHPStan-strict-rules forbids the short ternary `?:` operator, so the
     * `false`/result narrowing is expressed with an explicit if-check.
     */
    private function parseIsoDate(string $iso): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $iso);

        return $parsed === false ? null : $parsed;
    }

    /**
     * Flatten a Heading node's inline children into a single string by
     * concatenating Text node literals.
     *
     * Skips non-Text inlines (Link, Code, Emphasis) — for version headers
     * those almost never appear, and when they do (e.g. `## [1.2.3]` where
     * `[1.2.3]` is a Markdown link), the Text child of the Link still
     * carries the bracketed version literal.
     */
    private function flattenHeadingText(Heading $heading): string
    {
        $parts = [];
        foreach ($heading->iterator() as $child) {
            if ($child instanceof Text) {
                $parts[] = $child->getLiteral();
            }
        }

        return trim(implode('', $parts));
    }
}
