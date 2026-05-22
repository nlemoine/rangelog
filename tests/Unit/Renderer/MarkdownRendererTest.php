<?php

declare(strict_types=1);

use n5s\Rangelog\Domain\Changelog;
use n5s\Rangelog\Domain\ChangelogEntry;
use n5s\Rangelog\Renderer\MarkdownRenderer;
use n5s\Rangelog\Renderer\RendererInterface;
use n5s\Rangelog\Tests\TestSupport\ArrayLogger;

// ---------------------------------------------------------------------------
// Structural / construction assertions
// ---------------------------------------------------------------------------

it('is a final class implementing RendererInterface', function (): void {
    $reflection = new ReflectionClass(MarkdownRenderer::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->implementsInterface(RendererInterface::class))->toBeTrue();
});

it('has a zero-knob single-parameter constructor', function (): void {
    $ctor = new ReflectionMethod(MarkdownRenderer::class, '__construct');

    expect($ctor->getNumberOfParameters())->toBe(1);
});

it('accepts an optional LoggerInterface and defaults to NullLogger', function (): void {
    // No-arg construction must succeed (NullLogger default).
    $rendererDefault = new MarkdownRenderer();
    expect($rendererDefault)->toBeInstanceOf(MarkdownRenderer::class);

    // Explicit ArrayLogger injection must also succeed.
    $rendererInjected = new MarkdownRenderer(new ArrayLogger());
    expect($rendererInjected)->toBeInstanceOf(MarkdownRenderer::class);
});

// ---------------------------------------------------------------------------
// Heading format, em-dash, sourceUrl link, body
// ---------------------------------------------------------------------------

it('renders newest-first H2 headings with em-dash, date, sourceUrl link, and body', function (): void {
    $renderer = new MarkdownRenderer();

    $changelog = new Changelog([
        new ChangelogEntry(
            version: '1.0.0',
            date: new DateTimeImmutable('2025-12-01'),
            sections: [],
            raw: "- Initial release\n- First feature",
            sourceUrl: 'https://github.com/acme/widget/releases/tag/1.0.0',
        ),
        new ChangelogEntry(
            version: '1.2.0',
            date: new DateTimeImmutable('2026-01-15'),
            sections: [],
            raw: "- Bug fix\n- Performance improvement",
            sourceUrl: 'https://github.com/acme/widget/releases/tag/1.2.0',
        ),
    ]);

    $expected = "## [1.2.0](https://github.com/acme/widget/releases/tag/1.2.0) — 2026-01-15\n\n"
        . "- Bug fix\n- Performance improvement\n\n"
        . "## [1.0.0](https://github.com/acme/widget/releases/tag/1.0.0) — 2025-12-01\n\n"
        . "- Initial release\n- First feature";

    expect($renderer->render($changelog))->toBe($expected);
});

it('renders plain version heading when sourceUrl is null', function (): void {
    $renderer = new MarkdownRenderer();

    $changelog = new Changelog([
        new ChangelogEntry(
            version: '1.2.0',
            date: new DateTimeImmutable('2026-01-15'),
            sections: [],
            raw: '- body',
        ),
    ]);

    expect($renderer->render($changelog))->toBe("## 1.2.0 — 2026-01-15\n\n- body");
});

it('omits em-dash and date when date is null', function (): void {
    $renderer = new MarkdownRenderer();

    $changelog = new Changelog([
        new ChangelogEntry(
            version: '0.1.0',
            date: null,
            sections: [],
            raw: 'X',
            sourceUrl: 'https://example.com',
        ),
    ]);

    expect($renderer->render($changelog))->toBe("## [0.1.0](https://example.com)\n\nX");
});

it('renders bare version heading when both date AND sourceUrl are null', function (): void {
    $renderer = new MarkdownRenderer();

    $changelog = new Changelog([
        new ChangelogEntry(
            version: '0.1.0',
            date: null,
            sections: [],
            raw: 'Initial',
        ),
    ]);

    expect($renderer->render($changelog))->toBe("## 0.1.0\n\nInitial");
});

it('renders date-only heading when sourceUrl is null and date is present', function (): void {
    $renderer = new MarkdownRenderer();

    $changelog = new Changelog([
        new ChangelogEntry(
            version: '0.1.0',
            date: new DateTimeImmutable('2026-01-01'),
            sections: [],
            raw: 'X',
        ),
    ]);

    expect($renderer->render($changelog))->toBe("## 0.1.0 — 2026-01-01\n\nX");
});

it('separates entries with a single blank line, no horizontal rule', function (): void {
    $renderer = new MarkdownRenderer();

    $changelog = new Changelog([
        new ChangelogEntry(version: '1.0.0', date: null, sections: [], raw: 'a'),
        new ChangelogEntry(version: '2.0.0', date: null, sections: [], raw: 'b'),
    ]);

    $output = $renderer->render($changelog);

    expect($output)->not->toContain("\n\n\n");
    expect($output)->not->toContain("\n---\n");
});

// ---------------------------------------------------------------------------
// Sort policy
// ---------------------------------------------------------------------------

it('sorts entries newest-first via composer/semver', function (): void {
    $renderer = new MarkdownRenderer();

    $changelog = new Changelog([
        new ChangelogEntry(version: '1.0.0', date: null, sections: [], raw: 'one-zero'),
        new ChangelogEntry(version: '1.5.0', date: null, sections: [], raw: 'one-five'),
        new ChangelogEntry(version: '2.0.0', date: null, sections: [], raw: 'two-zero'),
    ]);

    $output = $renderer->render($changelog);

    $pos200 = strpos($output, '## 2.0.0');
    $pos150 = strpos($output, '## 1.5.0');
    $pos100 = strpos($output, '## 1.0.0');

    // `strpos` returns `int|false`; the test fails fast if any heading is
    // missing AND the narrowing is propagated to PHPStan via `is_int`.
    expect($pos200)->toBeInt();
    expect($pos150)->toBeInt();
    expect($pos100)->toBeInt();

    if (! is_int($pos200) || ! is_int($pos150) || ! is_int($pos100)) {
        throw new RuntimeException('Heading missing from rendered output');
    }

    expect($pos200)->toBeLessThan($pos150);
    expect($pos150)->toBeLessThan($pos100);
});

it('skips non-semver versions with one debug log event per skipped entry', function (): void {
    $logger = new ArrayLogger();
    $renderer = new MarkdownRenderer($logger);

    $changelog = new Changelog([
        new ChangelogEntry(version: '1.2.0', date: null, sections: [], raw: 'a'),
        new ChangelogEntry(version: 'main-branch', date: null, sections: [], raw: 'b'),
        new ChangelogEntry(version: 'not.a.version', date: null, sections: [], raw: 'c'),
    ]);

    $output = $renderer->render($changelog);

    expect($output)->toContain('## 1.2.0');
    expect($output)->not->toContain('main-branch');
    expect($output)->not->toContain('not.a.version');

    $skipRecords = array_values(array_filter(
        $logger->records,
        static fn (array $r): bool => $r['level'] === 'debug'
            && $r['message'] === 'Skipping non-semver version',
    ));

    expect($skipRecords)->toHaveCount(2);

    $skipped = [];
    foreach ($skipRecords as $record) {
        expect($record['context'])->toHaveKey('version');
        // The allowlist is narrowed to ONE key only: `version`.
        expect(array_keys($record['context']))->toBe(['version']);
        $skipped[] = $record['context']['version'];
    }
    sort($skipped);
    expect($skipped)->toBe(['main-branch', 'not.a.version']);
});

it('preserves insertion order on semver-equal versions (stable sort)', function (): void {
    $renderer = new MarkdownRenderer();

    $changelog = new Changelog([
        new ChangelogEntry(version: '1.2.3', date: null, sections: [], raw: 'FIRST'),
        new ChangelogEntry(version: '1.2.3', date: null, sections: [], raw: 'SECOND'),
    ]);

    $output = $renderer->render($changelog);

    $posFirst = strpos($output, 'FIRST');
    $posSecond = strpos($output, 'SECOND');

    expect($posFirst)->toBeInt();
    expect($posSecond)->toBeInt();

    if (! is_int($posFirst) || ! is_int($posSecond)) {
        throw new RuntimeException('Body block missing from rendered output');
    }

    expect($posFirst)->toBeLessThan($posSecond);
});

// ---------------------------------------------------------------------------
// Empty fallback
// ---------------------------------------------------------------------------

it('returns italic empty fallback for an empty Changelog', function (): void {
    $renderer = new MarkdownRenderer();

    expect($renderer->render(new Changelog([])))->toBe('_No changelog entries found._');
});

// ---------------------------------------------------------------------------
// Partial admonition
// ---------------------------------------------------------------------------

it('emits partial admonition at TOP with verbatim reason then entries', function (): void {
    $renderer = new MarkdownRenderer();

    $changelog = new Changelog(
        entries: [
            new ChangelogEntry(version: '1.5.0', date: null, sections: [], raw: 'minor bump'),
        ],
        isPartial: true,
        partialReason: 'WordPress.org API truncated entries before 1.0.0',
    );

    $expected = "> [!WARNING]\n> WordPress.org API truncated entries before 1.0.0\n\n## 1.5.0\n\nminor bump";

    expect($renderer->render($changelog))->toBe($expected);
});

it('falls back to default partial message when getPartialReason is null', function (): void {
    $renderer = new MarkdownRenderer();

    $changelog = new Changelog(
        entries: [
            new ChangelogEntry(version: '1.5.0', date: null, sections: [], raw: 'x'),
        ],
        isPartial: true,
    );

    $expected = "> [!WARNING]\n> Changelog is partial — some entries may be missing.\n\n## 1.5.0\n\nx";

    expect($renderer->render($changelog))->toBe($expected);
});

it('renders admonition then empty fallback when partial AND entries empty', function (): void {
    $renderer = new MarkdownRenderer();

    $changelog = new Changelog(
        entries: [],
        isPartial: true,
        partialReason: 'WP truncated',
    );

    $expected = "> [!WARNING]\n> WP truncated\n\n_No changelog entries found._";

    expect($renderer->render($changelog))->toBe($expected);
});

// ---------------------------------------------------------------------------
// Empty / whitespace-only raw → heading-only output
// ---------------------------------------------------------------------------

it('renders heading only when entry raw is empty or whitespace', function (): void {
    $renderer = new MarkdownRenderer();

    // (a) raw is the empty string -> heading only, no body separator
    $emptyRawChangelog = new Changelog([
        new ChangelogEntry(version: '0.1.0', date: null, sections: [], raw: ''),
    ]);
    expect($renderer->render($emptyRawChangelog))->toBe('## 0.1.0');

    // (b) whitespace-only raw trims to empty -> heading only
    $whitespaceRawChangelog = new Changelog([
        new ChangelogEntry(version: '0.1.0', date: null, sections: [], raw: "   \n\t  "),
    ]);
    expect($renderer->render($whitespaceRawChangelog))->toBe('## 0.1.0');
});

// ---------------------------------------------------------------------------
// Security: log allowlist + level allowlist
// ---------------------------------------------------------------------------

it('NEVER logs context keys outside the {version} allowlist and NEVER emits non-debug events', function (): void {
    $logger = new ArrayLogger();
    $renderer = new MarkdownRenderer($logger);

    // Exercise every code path that could log: a Changelog mixing semver + non-semver
    // entries triggers the only logging surface (the non-semver skip).
    $renderer->render(new Changelog([
        new ChangelogEntry(version: '1.0.0', date: null, sections: [], raw: 'a'),
        new ChangelogEntry(version: 'main-branch', date: null, sections: [], raw: 'b'),
        new ChangelogEntry(version: 'not.a.version', date: null, sections: [], raw: 'c'),
        new ChangelogEntry(version: '', date: null, sections: [], raw: 'd'),
    ]));

    // Renderer-only re-run with empty + partial to exercise non-logging code paths too.
    $renderer->render(new Changelog([]));
    $renderer->render(new Changelog([], isPartial: true, partialReason: 'WP truncated'));

    foreach ($logger->records as $record) {
        // Only `debug` events are ever emitted; no info/notice/warning/error.
        expect($record['level'])->toBe('debug');

        // Context keys are restricted to the single key `version`.
        foreach ($record['context'] as $key => $value) {
            $keyString = is_string($key) ? $key : (string) $key;
            expect($keyString)->toBe('version');
            // Value must be a string — never PSR-7 messages, bodies, headers.
            expect(is_string($value))->toBeTrue();
        }
    }
});
