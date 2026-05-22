<?php

declare(strict_types=1);

namespace n5s\Rangelog\Domain;

/**
 * One Keep-a-Changelog-style section within an entry: "Added", "Fixed",
 * "Changed", "Deprecated", "Removed", "Security", or any custom title.
 *
 * The $lines array is pre-stripped of bullet markers — caller renders
 * them back if needed.
 */
final readonly class ChangelogSection
{
    /**
     * @param string[] $lines
     */
    public function __construct(
        public string $title,
        public array $lines,
    ) {
    }
}
