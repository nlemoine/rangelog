<?php

declare(strict_types=1);

namespace n5s\Rangelog\Domain;

use DateTimeImmutable;

/**
 * Single per-version entry within a Changelog.
 *
 * The $raw field carries the original markdown / readme.txt block for
 * the renderer to splat unchanged when structured rendering isn't
 * needed. The $sections array carries pre-parsed section data when the
 * parser was able to extract it (Keep-a-Changelog #### Added etc.).
 */
final readonly class ChangelogEntry
{
    /**
     * @param ChangelogSection[] $sections
     */
    public function __construct(
        public string $version,
        public ?DateTimeImmutable $date,
        public array $sections,
        public string $raw,
        public ?string $sourceUrl = null,
    ) {
    }
}
