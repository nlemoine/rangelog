<?php

declare(strict_types=1);

namespace n5s\Rangelog\Renderer;

use n5s\Rangelog\Domain\Changelog;

/**
 * Public extension contract — render a Changelog as a string.
 *
 * The library ships {@see MarkdownRenderer} as the default. Callers can
 * supply their own HTML / JSON / plain-text renderers by implementing this
 * interface.
 *
 * Implementations:
 *  - render(Changelog): string — produces the full output for the
 *    Changelog. Must be deterministic given the same input.
 *  - When the Changelog is empty, MUST return a non-empty fallback
 *    string, not '' or null.
 *  - When Changelog::isPartial(), MUST surface that visibly in the
 *    output.
 *
 * Implementations MUST be `final class`.
 */
interface RendererInterface
{
    public function render(Changelog $changelog): string;
}
