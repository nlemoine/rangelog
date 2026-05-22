<?php

declare(strict_types=1);

use n5s\Rangelog\Domain\Changelog;
use n5s\Rangelog\Domain\ChangelogEntry;
use n5s\Rangelog\Renderer\MarkdownRenderer;
use n5s\Rangelog\Renderer\RendererInterface;
use n5s\Rangelog\Renderer\TruncatingRenderer;

function makeEntryForTruncate(string $version, string $rawBody): ChangelogEntry
{
    return new ChangelogEntry(
        version: $version,
        date: null,
        sections: [],
        raw: $rawBody,
    );
}

// ---------------------------------------------------------------------------
// Structural assertions
// ---------------------------------------------------------------------------

it('is a final class implementing RendererInterface', function (): void {
    $r = new ReflectionClass(TruncatingRenderer::class);
    expect($r->isFinal())->toBeTrue();
    expect($r->implementsInterface(RendererInterface::class))->toBeTrue();
});

it('rejects a non-positive byte budget', function (int $bad): void {
    expect(fn (): TruncatingRenderer => new TruncatingRenderer(new MarkdownRenderer(), $bad))
        ->toThrow(InvalidArgumentException::class);
})->with([0, -1, -1024]);

// ---------------------------------------------------------------------------
// Happy path: under budget returns inner output verbatim
// ---------------------------------------------------------------------------

it('returns inner output unchanged when render fits the budget', function (): void {
    $inner = new MarkdownRenderer();
    $cl = new Changelog([
        makeEntryForTruncate('1.0.0', '- initial'),
        makeEntryForTruncate('1.1.0', '- second'),
    ]);

    $expected = $inner->render($cl);
    $renderer = new TruncatingRenderer($inner, maxBytes: 10_000);

    expect($renderer->render($cl))->toBe($expected);
});

it('returns inner output unchanged for empty Changelog', function (): void {
    $inner = new MarkdownRenderer();
    $cl = new Changelog([]);

    $renderer = new TruncatingRenderer($inner, maxBytes: 100);

    expect($renderer->render($cl))->toBe($inner->render($cl));
});

// ---------------------------------------------------------------------------
// Truncation behaviour
// ---------------------------------------------------------------------------

it('drops oldest entries to fit the byte budget and marks output partial', function (): void {
    $inner = new MarkdownRenderer();

    // 5 entries, body padded so each rendered block is ~150 bytes.
    $padding = str_repeat('x ', 60); // 120 bytes of body per entry
    $cl = new Changelog([
        makeEntryForTruncate('1.0.0', $padding),
        makeEntryForTruncate('1.1.0', $padding),
        makeEntryForTruncate('1.2.0', $padding),
        makeEntryForTruncate('1.3.0', $padding),
        makeEntryForTruncate('1.4.0', $padding),
    ]);

    // Pick a budget that fits 2-3 entries but not all 5.
    $renderer = new TruncatingRenderer($inner, maxBytes: 500);
    $out = $renderer->render($cl);

    expect(strlen($out))->toBeLessThanOrEqual(500);
    // Newest entries are kept; oldest (1.0.0) is dropped.
    expect($out)->toContain('## 1.4.0');
    expect($out)->not->toContain('## 1.0.0');
    // Partial admonition fires.
    expect($out)->toStartWith('> [!WARNING]');
    expect($out)->toContain('omitted');
});

it('uses singular "entry" in the partial reason when exactly one entry is dropped', function (): void {
    $inner = new MarkdownRenderer();

    // Render two near-identical entries; force truncation by setting maxBytes
    // just below the full render but above the K=1 render.
    $padding = str_repeat('x', 200);
    $cl = new Changelog([
        makeEntryForTruncate('1.0.0', $padding),
        makeEntryForTruncate('2.0.0', $padding),
    ]);

    $fullSize = strlen($inner->render($cl));
    $single = new Changelog([makeEntryForTruncate('2.0.0', $padding)]);
    $singleSize = strlen($inner->render($single));

    // Budget in the gap between K=1 and K=2.
    $budget = $singleSize + 100;
    expect($budget)->toBeLessThan($fullSize);

    $renderer = new TruncatingRenderer($inner, maxBytes: $budget);
    $out = $renderer->render($cl);

    expect($out)->toContain('1 older entry omitted');
});

it('uses plural "entries" in the partial reason when more than one is dropped', function (): void {
    $inner = new MarkdownRenderer();
    $padding = str_repeat('y', 200);
    $cl = new Changelog([
        makeEntryForTruncate('1.0.0', $padding),
        makeEntryForTruncate('1.1.0', $padding),
        makeEntryForTruncate('1.2.0', $padding),
    ]);

    $renderer = new TruncatingRenderer($inner, maxBytes: 350);
    $out = $renderer->render($cl);

    expect($out)->toContain('older entries omitted');
    expect($out)->not->toContain('older entry omitted');
});

it('preserves the source partial reason when the input was already partial', function (): void {
    $inner = new MarkdownRenderer();
    $padding = str_repeat('z', 200);
    $cl = new Changelog(
        entries: [
            makeEntryForTruncate('1.0.0', $padding),
            makeEntryForTruncate('1.1.0', $padding),
            makeEntryForTruncate('1.2.0', $padding),
        ],
        isPartial: true,
        partialReason: 'upstream truncated at 10KB',
    );

    $renderer = new TruncatingRenderer($inner, maxBytes: 350);
    $out = $renderer->render($cl);

    expect($out)->toContain('upstream truncated at 10KB');
    expect($out)->toContain('older entries omitted');
});

it('keeps newest entries by semver (not by Changelog::$entries position)', function (): void {
    $inner = new MarkdownRenderer();
    $padding = str_repeat('w', 200);

    // Out-of-order input: oldest first, newest last in $entries.
    $cl = new Changelog([
        makeEntryForTruncate('1.0.0', $padding),
        makeEntryForTruncate('3.0.0', $padding),
        makeEntryForTruncate('2.0.0', $padding),
    ]);

    // Budget for one entry. The kept entry must be 3.0.0 (highest semver),
    // not whatever happens to be at position 0.
    $singleRender = $inner->render(new Changelog([makeEntryForTruncate('3.0.0', $padding)]));
    $budget = strlen($singleRender) + 100;

    $renderer = new TruncatingRenderer($inner, maxBytes: $budget);
    $out = $renderer->render($cl);

    expect($out)->toContain('## 3.0.0');
    expect($out)->not->toContain('## 2.0.0');
    expect($out)->not->toContain('## 1.0.0');
});

it('returns inner output unchanged when even the empty-partial render exceeds budget', function (): void {
    $inner = new MarkdownRenderer();
    $cl = new Changelog([
        makeEntryForTruncate('1.0.0', 'body'),
    ]);

    // Tiny budget: even an empty Changelog renders ~30 bytes, plus the
    // partial admonition. Set budget to 1 byte — nothing can fit.
    $renderer = new TruncatingRenderer($inner, maxBytes: 1);
    $out = $renderer->render($cl);

    // Best-effort: returns the full inner render unchanged.
    expect($out)->toBe($inner->render($cl));
});
