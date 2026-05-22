<?php

declare(strict_types=1);

namespace n5s\Rangelog\Domain;

/**
 * Version range value object — captures from/to with inclusivity flags.
 *
 * Default semantics: exclusive-from / inclusive-to, which describes "what
 * changed since I upgraded". The named factories provide intent-revealing
 * call sites:
 *
 *   VersionRange::changes('1.0.0', '2.0.0')    // (1.0.0, 2.0.0]
 *   VersionRange::inclusive('1.0.0', '2.0.0')  // [1.0.0, 2.0.0]
 *
 * Comparison logic lives in Changelog::filter() — VersionRange is a pure
 * data structure with no semver knowledge.
 */
final readonly class VersionRange
{
    public function __construct(
        public string $from,
        public string $to,
        public bool $includeFrom = false,
        public bool $includeTo = true,
    ) {
    }

    /**
     * Default semantics: exclusive-from / inclusive-to.
     * Use for "what changed since version X" queries.
     */
    public static function changes(string $from, string $to): self
    {
        return new self($from, $to, includeFrom: false, includeTo: true);
    }

    /** Both ends inclusive — full upgrade-path window. */
    public static function inclusive(string $from, string $to): self
    {
        return new self($from, $to, includeFrom: true, includeTo: true);
    }
}
