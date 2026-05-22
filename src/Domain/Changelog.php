<?php

declare(strict_types=1);

namespace n5s\Rangelog\Domain;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use UnexpectedValueException;

/**
 * Ordered collection of ChangelogEntry plus partial-result signalling.
 *
 * WordPress.org's plugin API truncates the changelog section at ~10 KB; older
 * entries silently disappear. Resolvers that detect truncation set
 * isPartial=true and partialReason to a human description. The renderer
 * surfaces this prominently.
 *
 * filter(VersionRange) is the AUTHORITATIVE version-range boundary in the
 * system. Parsers do NOT filter — they return the full Changelog and the
 * domain model handles range boundaries here. This keeps parsers ignorant of
 * inclusivity semantics.
 *
 * Non-semver entries (e.g. WP date-versioned plugins like '20231015' or
 * malformed strings) are silently SKIPPED during filter. Throwing on a single
 * malformed entry would kill the whole result; the BYO ethos is "best-effort,
 * never explode".
 *
 * filter() normalizes BOTH the entry version and the range bounds via
 * Composer\Semver\VersionParser::normalize() before each Comparator call.
 * Without normalization, Comparator returns wrong results across v-prefix
 * mismatches (e.g. `greaterThan('v7.4.9', '7.4.7')` returns `false`). A
 * non-semver range bound causes ALL entries to drop — the early-return in
 * filter() makes this explicit. A VersionParser instance and the two
 * normalized range bounds are computed ONCE per filter() call and passed to
 * matches().
 */
final readonly class Changelog
{
    /**
     * @param ChangelogEntry[] $entries
     */
    public function __construct(
        public array $entries,
        public bool $isPartial = false,
        public ?string $partialReason = null,
    ) {
    }

    public function isPartial(): bool
    {
        return $this->isPartial;
    }

    public function getPartialReason(): ?string
    {
        return $this->partialReason;
    }

    /**
     * Filter the entries by VersionRange.
     *
     * Returns a NEW Changelog (immutable original). isPartial / partialReason
     * are preserved from the source: filtering does not change the truth
     * about whether the source data was truncated.
     *
     * VersionParser is instantiated once here and the two range-bound
     * normalizations run once per call (hoisted out of the per-entry loop).
     * If either range bound is non-semver, an early-return yields an empty
     * Changelog (same partial/reason metadata preserved). Non-semver ENTRY
     * versions continue to be silently skipped per-entry inside matches().
     */
    public function filter(VersionRange $range): self
    {
        $parser = new VersionParser();

        // Normalize range bounds once — a non-semver bound means no entry can
        // match; return early with an empty Changelog.
        try {
            $normalizedFrom = $parser->normalize($range->from);
            $normalizedTo   = $parser->normalize($range->to);
        } catch (UnexpectedValueException) {
            return new self(
                entries: [],
                isPartial: $this->isPartial,
                partialReason: $this->partialReason,
            );
        }

        $kept = [];

        foreach ($this->entries as $entry) {
            if ($this->matches($entry->version, $range, $parser, $normalizedFrom, $normalizedTo)) {
                $kept[] = $entry;
            }
        }

        return new self(
            entries: $kept,
            isPartial: $this->isPartial,
            partialReason: $this->partialReason,
        );
    }

    private function matches(
        string $version,
        VersionRange $range,
        VersionParser $parser,
        string $normalizedFrom,
        string $normalizedTo,
    ): bool {
        try {
            // Normalize the entry version via composer/semver VersionParser before
            // the Comparator calls. Without normalization, mixed v-prefix inputs
            // produce wrong results (e.g. greaterThan('v7.4.9', '7.4.7') returns false)
            // because v-strings sort differently when only one side carries the prefix.
            // Range bounds ($normalizedFrom / $normalizedTo) are already normalized by
            // the caller (filter()).
            $normalizedVersion = $parser->normalize($version);

            $satisfiesFrom = $range->includeFrom
                ? Comparator::greaterThanOrEqualTo($normalizedVersion, $normalizedFrom)
                : Comparator::greaterThan($normalizedVersion, $normalizedFrom);

            if (! $satisfiesFrom) {
                return false;
            }

            return $range->includeTo
                ? Comparator::lessThanOrEqualTo($normalizedVersion, $normalizedTo)
                : Comparator::lessThan($normalizedVersion, $normalizedTo);
        } catch (UnexpectedValueException) {
            // Non-semver entry version: silently skip rather than fail the whole
            // filter. Range-bound normalization failures are handled upstream in
            // filter() via the early-return, so this catch is scoped to the
            // entry-side normalize() and the four Comparator calls.
            return false;
        }
    }
}
