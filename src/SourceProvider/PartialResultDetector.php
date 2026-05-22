<?php

declare(strict_types=1);

namespace n5s\Rangelog\SourceProvider;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use n5s\Rangelog\Domain\Changelog;
use n5s\Rangelog\Domain\VersionRange;
use UnexpectedValueException;

/**
 * Static utility that marks a Changelog as partial when the `from` version
 * is absent from its entries.
 *
 * Ships as a static utility with no instance state. Calling code never
 * instantiates this class; the static method acts as a pure function over
 * immutable value objects.
 *
 * Wiring: the Rangelog orchestrator calls
 *   parse → markPartialIfFromMissing → filter
 *
 * Non-semver entry versions (e.g. WP date-versioned "20231015", or
 * malformed strings) are silently skipped via try/catch on
 * UnexpectedValueException. A single bad entry version must never poison
 * the whole result.
 *
 * $range->from is caller-supplied (not third-party). The partialReason
 * string is user-facing; no PII expected.
 */
final class PartialResultDetector
{
    public static function markPartialIfFromMissing(Changelog $changelog, VersionRange $range): Changelog
    {
        // Preserve existing partialReason — do not overwrite.
        if ($changelog->isPartial) {
            return $changelog;
        }

        $parser = new VersionParser();

        try {
            $normalizedFrom = $parser->normalize($range->from);
        } catch (UnexpectedValueException) {
            // Caller-supplied from is non-semver — cannot match any entry; mark partial.
            $normalizedFrom = null;
        }

        if ($normalizedFrom !== null) {
            foreach ($changelog->entries as $entry) {
                try {
                    $normalizedEntry = $parser->normalize($entry->version);

                    if (Comparator::equalTo($normalizedEntry, $normalizedFrom)) {
                        return $changelog; // from version found — not partial
                    }
                } catch (UnexpectedValueException) {
                    // Non-semver entry version — skip.
                }
            }
        }

        return new Changelog(
            entries: $changelog->entries,
            isPartial: true,
            partialReason: "from version {$range->from} not present in source",
        );
    }
}
