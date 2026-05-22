<?php

declare(strict_types=1);

namespace n5s\Rangelog\Renderer;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use InvalidArgumentException;
use n5s\Rangelog\Domain\Changelog;
use n5s\Rangelog\Domain\ChangelogEntry;
use UnexpectedValueException;

/**
 * Renderer decorator that enforces a maximum byte budget on the inner
 * renderer's output by dropping older entries (lowest semver) until the
 * rendered string fits.
 *
 * Useful for environments with a hard size cap (GitHub PR body ~65 KB,
 * Slack messages, email digests). The decorator iterates over a binary
 * search of "keep top-K entries", picks the largest K that fits, and
 * marks the resulting Changelog partial with a reason describing the
 * truncation so the inner renderer's existing partial-result admonition
 * (e.g. `> [!WARNING]`) fires at the top of the output.
 *
 * When the inner renderer's full output already fits, the decorator is a
 * pass-through. When even an empty-partial render exceeds the budget
 * (pathologically small `$maxBytes`), the decorator falls back to the
 * inner renderer's full output: best-effort, the caller can detect the
 * size overflow themselves.
 *
 * Sort logic mirrors {@see MarkdownRenderer} (semver desc via
 * `composer/semver` `Comparator`, non-semver entries silently skipped).
 * If the inner renderer is not `MarkdownRenderer`, the entries kept will
 * still be the top-K by semver, but the rendered output is whatever the
 * inner renderer produces from that subset.
 */
final readonly class TruncatingRenderer implements RendererInterface
{
    public function __construct(
        private RendererInterface $inner,
        private int $maxBytes,
    ) {
        if ($maxBytes < 1) {
            throw new InvalidArgumentException(
                "maxBytes must be >= 1, got {$maxBytes}",
            );
        }
    }

    public function render(Changelog $changelog): string
    {
        $fullOutput = $this->inner->render($changelog);
        if (\strlen($fullOutput) <= $this->maxBytes) {
            return $fullOutput;
        }

        $sorted = $this->semverDescending($changelog->entries);
        $total  = \count($sorted);

        // Binary search for the largest K in [0, $total] where rendering
        // the top-K entries (with partial=true) fits the byte budget.
        $lo   = 0;
        $hi   = $total;
        $best = null;

        while ($lo <= $hi) {
            $mid       = ($lo + $hi) >> 1;
            $kept      = \array_slice($sorted, 0, $mid);
            $omitted   = $total - $mid;
            $candidate = $this->withTruncation($changelog, $kept, $omitted);
            $rendered  = $this->inner->render($candidate);

            if (\strlen($rendered) <= $this->maxBytes) {
                $best = $rendered;
                $lo   = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        return $best ?? $fullOutput;
    }

    /**
     * @param  ChangelogEntry[]     $kept
     */
    private function withTruncation(Changelog $original, array $kept, int $omitted): Changelog
    {
        if ($omitted === 0) {
            // Same content, no truncation: preserve the original partial state.
            return new Changelog(
                entries: $kept,
                isPartial: $original->isPartial,
                partialReason: $original->partialReason,
            );
        }

        $noun   = $omitted === 1 ? 'entry' : 'entries';
        $reason = "{$omitted} older {$noun} omitted to fit {$this->maxBytes}-byte budget";

        if ($original->isPartial && $original->partialReason !== null) {
            $reason .= "; {$original->partialReason}";
        } elseif ($original->isPartial) {
            $reason .= '; source was already partial';
        }

        return new Changelog(
            entries: $kept,
            isPartial: true,
            partialReason: $reason,
        );
    }

    /**
     * Filter non-semver entries and sort the survivors newest-first.
     *
     * Mirrors {@see MarkdownRenderer::sortEntries()} so that the
     * "kept top-K" subset matches what the renderer would surface. A
     * private duplicate is preferred over exposing the sort on the
     * public interface.
     *
     * @param  ChangelogEntry[]       $entries
     * @return list<ChangelogEntry>
     */
    private function semverDescending(array $entries): array
    {
        $parser = new VersionParser();
        $semver = [];

        foreach ($entries as $entry) {
            try {
                $parser->normalize($entry->version);
            } catch (UnexpectedValueException) {
                continue;
            }
            $semver[] = $entry;
        }

        usort(
            $semver,
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

        return $semver;
    }
}
