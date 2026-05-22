<?php

declare(strict_types=1);

namespace n5s\Rangelog\Renderer;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use DateTimeImmutable;
use n5s\Rangelog\Domain\Changelog;
use n5s\Rangelog\Domain\ChangelogEntry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use UnexpectedValueException;

/**
 * GFM-flavoured markdown renderer for the public `Changelog` value object.
 *
 *  - One `## {version}` H2 heading per entry, with optional em-dash + ISO
 *    date suffix (`## 1.2.3 — 2026-01-15`) and optional markdown link form
 *    when `sourceUrl !== null` (`## [1.2.3](https://...) — 2026-01-15`).
 *    Entries are separated by a single blank line (`"\n\n"` join over a
 *    list-of-blocks).
 *
 *  - Entries are sorted newest-first via composer/semver `Comparator`;
 *    non-semver entries are silently SKIPPED before sort via a
 *    `VersionParser::normalize()` guard. Comparator alone does NOT throw
 *    on `dev-master` / `main-branch` / non-semver strings; only
 *    `VersionParser::normalize` surfaces the parse failure as
 *    `UnexpectedValueException`. PHP 8.0+ `usort` is stable on
 *    equal-comparator returns.
 *
 *  - When the rendered entry list is empty (no entries, or every entry was
 *    dropped as non-semver), the output is the literal italic fallback
 *    `_No changelog entries found._`.
 *
 *  - When `Changelog::isPartial()` is `true`, the output BEGINS with a GFM
 *    alert `> [!WARNING]\n> {reason}` block, above any entries / empty
 *    fallback. `getPartialReason()` is emitted verbatim; when null, the
 *    deterministic fallback `Changelog is partial — some entries may be
 *    missing.` is used.
 *
 *  - `$entry->raw` is trimmed and emitted verbatim beneath the heading
 *    (trust contract — parsers strip version headers before populating
 *    `raw`). Empty / whitespace-only raw collapses to a heading-only
 *    block.
 *
 *  - Zero-knob single-parameter constructor; `?LoggerInterface $logger = null`
 *    last + only param, defaulting to `new NullLogger()`.
 *
 *  - The single PSR-3 event emitted by this class is `debug`
 *    `'Skipping non-semver version'` with context `['version' => string]`
 *    only. No `info`/`notice`/`warning`/`error` events. No additional
 *    context keys (the allowlist is narrower than the parsers — they also
 *    expose `source` and `count`; the renderer has no equivalent surface).
 *
 * Output policy: the renderer NEVER appends a trailing newline. Output
 * ends at the last block's last non-whitespace character so Pest snapshot
 * assertions stay stable.
 *
 * Trust contracts (NOT sanitised by this layer):
 *  - `$entry->raw` and `$changelog->getPartialReason()` are emitted
 *    verbatim. Parsers and the `PartialResultDetector` are the upstream
 *    sanitisation boundary. GitHub's markdown renderer is the downstream
 *    sanitisation boundary for PR comments. The renderer is NOT an XSS
 *    sink.
 *  - `$entry->sourceUrl` is emitted unescaped inside a markdown link.
 *    Resolvers produce well-formed HTTPS URLs.
 *
 * Known limitation (intentional in v1):
 *  - A `$entry->raw` body containing a literal `## ` heading at line
 *    start will produce a header-level collision in the rendered
 *    output. Accepted: the only v1 producers (the bundled parsers) strip
 *    their own version headers before emitting `raw`.
 */
final readonly class MarkdownRenderer implements RendererInterface
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function render(Changelog $changelog): string
    {
        /** @var list<string> $blocks */
        $blocks = [];

        // Partial-result admonition at the TOP, above any entries / empty
        // fallback. GFM alert syntax requires the keyword on its own line.
        if ($changelog->isPartial()) {
            $reason = $changelog->getPartialReason()
                ?? 'Changelog is partial — some entries may be missing.';
            $blocks[] = "> [!WARNING]\n> {$reason}";
        }

        $entries = $this->sortEntries($changelog->entries);

        // Empty fallback (also handles the partial+empty edge case
        // naturally because the admonition block has already been pushed).
        if ($entries === []) {
            $blocks[] = '_No changelog entries found._';

            return implode("\n\n", $blocks);
        }

        foreach ($entries as $entry) {
            $blocks[] = $this->renderEntry($entry);
        }

        return implode("\n\n", $blocks);
    }

    /**
     * Skip non-semver entries via the `VersionParser::normalize` guard
     * (Comparator alone does not throw on non-semver strings) and sort
     * survivors newest-first via Comparator.
     *
     * @param  ChangelogEntry[]       $entries
     * @return list<ChangelogEntry>
     */
    private function sortEntries(array $entries): array
    {
        $versionParser = new VersionParser();
        $semverEntries = [];

        foreach ($entries as $entry) {
            try {
                $versionParser->normalize($entry->version);
            } catch (UnexpectedValueException) {
                $this->logger->debug(
                    'Skipping non-semver version',
                    ['version' => $entry->version],
                );

                continue;
            }

            $semverEntries[] = $entry;
        }

        // Newest-first stable sort via Comparator. The try/catch is
        // defence-in-depth — on already-normalized inputs Comparator should
        // not throw. Mirrors src/Domain/Changelog::matches(). Long-form
        // closure (not arrow) because phpstan-strict-rules forbids the
        // nested-ternary one-liner.
        usort(
            $semverEntries,
            static function (ChangelogEntry $a, ChangelogEntry $b): int {
                try {
                    if (Comparator::greaterThan($b->version, $a->version)) {
                        return 1;
                    }
                    if (Comparator::lessThan($b->version, $a->version)) {
                        return -1;
                    }

                    return 0;
                } catch (UnexpectedValueException) {
                    return 0;
                }
            },
        );

        return $semverEntries;
    }

    private function renderEntry(ChangelogEntry $entry): string
    {
        // Markdown-link form when sourceUrl is present, plain version
        // otherwise. No URL-escaping in v1 — resolvers emit valid HTTPS
        // URLs and the v1 known-limitation is documented in the class
        // docblock.
        $versionLabel = $entry->sourceUrl !== null
            ? "[{$entry->version}]({$entry->sourceUrl})"
            : $entry->version;

        // Em-dash + ISO date suffix when date is present. The em-dash is a
        // literal U+2014 character in the PHP source — not `&mdash;`, not
        // `--`. All project source files are UTF-8.
        $dateSuffix = $entry->date instanceof DateTimeImmutable
            ? ' — ' . $entry->date->format('Y-m-d')
            : '';

        // H2 heading.
        $heading = "## {$versionLabel}{$dateSuffix}";

        // Body trimmed; empty / whitespace-only raw collapses to a
        // heading-only block.
        $body = trim($entry->raw);
        if ($body === '') {
            return $heading;
        }

        return "{$heading}\n\n{$body}";
    }
}
