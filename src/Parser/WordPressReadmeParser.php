<?php

declare(strict_types=1);

namespace n5s\Rangelog\Parser;

use Composer\Semver\VersionParser;
use DateTimeImmutable;
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
 * Parses WordPress.org `readme.txt` `== Changelog ==` sections into Changelog
 * value objects via a custom line-walker.
 *
 * This parser is a custom line-walker rather than a wrapper around an
 * external WP-readme library. External wrappers tend to return the changelog
 * as one rendered-HTML blob (no per-version structure) and pin to date-based
 * release schemes that would couple this library's BC promise to an external
 * version it cannot influence.
 *
 * WP readme dialect:
 *  - Top-level sections: `== Section Name ==` (exactly two `=` per side).
 *  - Per-version sub-headers: `= Version =` (exactly one `=` per side).
 *  - Changelog header is case-insensitive on "changelog".
 *  - Per-version body lines are captured verbatim between version headers;
 *    line endings collapsed to `\n` via `preg_split('/\r\n|\n|\r/', …)`.
 *
 * @warning Untrusted readme.txt content — `ChangelogEntry.raw` carries
 *          the per-version body verbatim. WP readmes are wiki-markup that
 *          may contain raw HTML or shortcodes. Caller must sanitize before
 *          HTML rendering. The parser intentionally does NOT sanitize;
 *          structured data preservation is its job.
 *
 * Logging:
 *  - debug "Skipping non-semver version" — once per non-semver header
 *    encountered inside the Changelog section; context {version, source}.
 *  - debug "Parsed {count} entries from {source}" — once per successful
 *    parse (also fires when count = 0 — empty Changelog section is a
 *    valid degenerate case, not an exception).
 *  - Context keys are restricted to {version, source, count}.
 *
 * Empty / malformed input thresholds:
 *  - No `== Changelog ==` header at all → throws ParseException.
 *    The parser needs SOMETHING to anchor on.
 *  - `== Changelog ==` present but contains zero `= ... =` entries
 *    (boundary or EOF immediately follows) → returns Changelog([]).
 *
 * Regex DoS: all three regexes are anchored `^...$` with bounded character
 * classes; phpstan-strict-rules' PCRE checks audit them at static-analysis
 * time.
 *
 * This implementation does NOT filter the result by `$range` —
 * {@see \n5s\Rangelog\Domain\Changelog::filter()} is the authoritative
 * boundary. The argument is accepted for future parser-side optimisation
 * hints (e.g. early-exit on out-of-range versions).
 */
final readonly class WordPressReadmeParser implements ChangelogParserInterface
{
    /** Whole-line match for `== Changelog ==` (case-insensitive). */
    private const string CHANGELOG_HEADER = '/^==\s*changelog\s*==\s*$/i';

    /** Any top-level section header `== Name ==`. */
    private const string SECTION_BOUNDARY = '/^==\s*[^=]+==\s*$/';

    /**
     * Permissive `= X =` sub-header detector — captures group 1 = inner content.
     *
     * Anchored with bounded `[^=]+?` (no unbounded `.*`). The `\s+` requires
     * at least one inner whitespace on each side of the capture group, so
     * accidental body literals like `=foo=` or `=note=` do NOT match.
     * Admits both real semver headers like `= 1.2.3 =` AND legacy non-semver
     * lines like `= Initial release =` so the semver normalize step can
     * filter the latter via the bare `catch (UnexpectedValueException)` and
     * log a debug skip — both have inner whitespace on each side.
     */
    private const string CANDIDATE_HEADER = '/^=\s+([^=]+?)\s+=\s*$/';

    /**
     * Master version-header regex.
     *
     * Captures (best-effort) the cleaned version and optional date when the
     * candidate line matches a known WP version-header variant:
     *   group 1 — raw version string (2-4 components, optional pre-release/build metadata)
     *   group 2 — optional ISO date (Y-m-d) when present after the version
     *
     * Variants covered:
     *   = 1.2.3 =
     *   = v1.2.3 =
     *   = 1.2.3 - 2026-01-15 =
     *   = 1.2.3 2026-05-11 =                  (WooCommerce space-date)
     *   = 1.2.3 (2026-01-15) =
     *   = Version 1.2.3 =
     *   = 6.7.0.2 =                           (ACF 4-component)
     *   = 10.8.0-beta.2 2026-05-11 =          (pre-release + space-date)
     */
    private const string VERSION_HEADER =
        '/^=\s*(?:Version\s+)?v?'
        . '([\d]+(?:\.\d+){1,3}(?:[-+][\w.\-+]+)?)'
        . '\s*'
        . '(?:[-–(\s]\s*'
        . '(\d{4}-\d{2}-\d{2}|\d{1,2}[-\/. ]\w+[-\/. ]\d{2,4})'
        . '[)\s]*)?'
        . '\s*=\s*$/i';

    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function parse(RawResponse $response, VersionRange $range): Changelog
    {
        $source = $response->source->url;

        $lines = preg_split('/\r\n|\n|\r/', $response->body);
        if ($lines === false) {
            throw new ParseException('Failed to split WordPress readme body into lines');
        }

        $inChangelog = false;
        $sawChangelogHeader = false;
        /** @var list<array{version: string, date: ?DateTimeImmutable, body: list<string>}> $blocks */
        $blocks = [];
        /** @var array{version: string, date: ?DateTimeImmutable, body: list<string>}|null $currentBlock */
        $currentBlock = null;

        foreach ($lines as $line) {
            if (preg_match(self::CHANGELOG_HEADER, $line) === 1) {
                $inChangelog = true;
                $sawChangelogHeader = true;
                continue;
            }

            if (!$inChangelog) {
                continue;
            }

            if (preg_match(self::SECTION_BOUNDARY, $line) === 1) {
                if ($currentBlock !== null) {
                    $blocks[] = $currentBlock;
                    $currentBlock = null;
                }

                break;
            }

            // Two-stage version-header detection:
            //   1. The permissive CANDIDATE_HEADER matches any `= X =` line —
            //      sufficient anchor to start a new block AND lets non-semver
            //      headers reach the VersionParser->normalize() guard so the
            //      debug skip log fires.
            //   2. The strict VERSION_HEADER refines the version + captures
            //      the optional date when the line matches a known variant.
            $cm = [];
            if (preg_match(self::CANDIDATE_HEADER, $line, $cm) === 1) {
                if ($currentBlock !== null) {
                    $blocks[] = $currentBlock;
                }

                $rawVersion = $cm[1];
                $date = null;

                $vm = [];
                if (preg_match(self::VERSION_HEADER, $line, $vm) === 1) {
                    $rawVersion = $vm[1];

                    $dateField = $vm[2] ?? null;
                    if (\is_string($dateField)) {
                        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $dateField);
                        if ($parsed instanceof DateTimeImmutable) {
                            $date = $parsed;
                        }
                    }
                }

                $currentBlock = [
                    'version' => $rawVersion,
                    'date' => $date,
                    'body' => [],
                ];

                continue;
            }

            if ($currentBlock !== null) {
                $currentBlock['body'][] = $line;
            }
        }

        if ($currentBlock !== null) {
            $blocks[] = $currentBlock;
        }

        if (!$sawChangelogHeader) {
            throw new ParseException('No == Changelog == section found in WordPress readme');
        }

        $entries = [];
        $versionParser = new VersionParser();
        foreach ($blocks as $block) {
            try {
                $versionParser->normalize($block['version']);
            } catch (UnexpectedValueException) {
                $this->logger->debug('Skipping non-semver version', [
                    'version' => $block['version'],
                    'source' => $source,
                ]);

                continue;
            }

            $entries[] = new ChangelogEntry(
                version: $block['version'],
                date: $block['date'],
                sections: [new ChangelogSection(title: '', lines: $block['body'])],
                raw: implode("\n", $block['body']),
                sourceUrl: $source,
            );
        }

        $this->logger->debug('Parsed {count} entries from {source}', [
            'count' => \count($entries),
            'source' => $source,
        ]);

        return new Changelog($entries);
    }
}
