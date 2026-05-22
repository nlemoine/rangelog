<?php

declare(strict_types=1);

use n5s\Rangelog\Domain\Changelog;
use n5s\Rangelog\Domain\ChangelogEntry;
use n5s\Rangelog\Renderer\MarkdownRenderer;
use n5s\Rangelog\Renderer\RendererInterface;
use n5s\Rangelog\Renderer\SanitizingMarkdownRenderer;

function makeEntryForSanitize(string $version, string $raw): ChangelogEntry
{
    return new ChangelogEntry(
        version: $version,
        date: null,
        sections: [],
        raw: $raw,
    );
}

// ---------------------------------------------------------------------------
// Structural
// ---------------------------------------------------------------------------

it('is a final class implementing RendererInterface', function (): void {
    $r = new ReflectionClass(SanitizingMarkdownRenderer::class);
    expect($r->isFinal())->toBeTrue();
    expect($r->implementsInterface(RendererInterface::class))->toBeTrue();
});

it('rejects construction when no transform is configured', function (): void {
    expect(fn (): SanitizingMarkdownRenderer => new SanitizingMarkdownRenderer(
        inner: new MarkdownRenderer(),
        stripMentions: false,
    ))->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// Mention stripping
// ---------------------------------------------------------------------------

it('strips @user mentions from entry body text', function (): void {
    $cl = new Changelog([
        makeEntryForSanitize('1.0.0', 'Fix by @octocat in collaboration with @hubot.'),
    ]);

    $renderer = new SanitizingMarkdownRenderer(new MarkdownRenderer(), stripMentions: true);
    $out = $renderer->render($cl);

    expect($out)->toContain('octocat');
    expect($out)->toContain('hubot');
    expect($out)->not->toContain('@octocat');
    expect($out)->not->toContain('@hubot');
});

it('strips @org/team mentions', function (): void {
    $cl = new Changelog([
        makeEntryForSanitize('1.0.0', 'Reviewed by @security/admins, deployed.'),
    ]);

    $renderer = new SanitizingMarkdownRenderer(new MarkdownRenderer(), stripMentions: true);
    $out = $renderer->render($cl);

    expect($out)->toContain('security/admins');
    expect($out)->not->toContain('@security/admins');
});

it('preserves mentions inside fenced code blocks', function (): void {
    $body = <<<MD
        Update example:

        ```
        Notify @oncall when this fires.
        ```

        Real change by @octocat.
        MD;

    $cl = new Changelog([makeEntryForSanitize('1.0.0', $body)]);
    $renderer = new SanitizingMarkdownRenderer(new MarkdownRenderer(), stripMentions: true);
    $out = $renderer->render($cl);

    expect($out)->toContain('@oncall');                // inside code, untouched
    expect($out)->not->toContain('@octocat');          // outside code, stripped
    expect($out)->toContain('Real change by octocat'); // text preserved minus @
});

it('preserves mentions inside backtick code spans', function (): void {
    $cl = new Changelog([
        makeEntryForSanitize('1.0.0', 'Use `@octocat/handler` API. Real fix by @hubot.'),
    ]);

    $renderer = new SanitizingMarkdownRenderer(new MarkdownRenderer(), stripMentions: true);
    $out = $renderer->render($cl);

    expect($out)->toContain('`@octocat/handler`');   // inside backticks
    expect($out)->not->toContain('@hubot');           // outside backticks
    expect($out)->toContain('Real fix by hubot');
});

it('does not strip @ when followed by non-mention characters (e.g. email-like)', function (): void {
    $cl = new Changelog([
        makeEntryForSanitize('1.0.0', 'Contact ops@example.com or @octocat for help.'),
    ]);

    $renderer = new SanitizingMarkdownRenderer(new MarkdownRenderer(), stripMentions: true);
    $out = $renderer->render($cl);

    // ops@example.com is left intact (no @\w+\b pattern at @ exam… wait it would match @example actually).
    // The intent: only \b@\w pattern (word boundary BEFORE @) qualifies.
    expect($out)->toContain('ops@example.com');
    expect($out)->not->toContain('@octocat');
});

// ---------------------------------------------------------------------------
// Link rewriting
// ---------------------------------------------------------------------------

it('rewrites markdown link URLs via the callback', function (): void {
    $cl = new Changelog([
        makeEntryForSanitize('1.0.0', 'See [the docs](https://example.com/docs) for details.'),
    ]);

    $renderer = new SanitizingMarkdownRenderer(
        inner: new MarkdownRenderer(),
        linkRewriter: fn (string $url): string => 'https://redirect.example/?to=' . urlencode($url),
    );
    $out = $renderer->render($cl);

    expect($out)->toContain('[the docs](https://redirect.example/?to=' . urlencode('https://example.com/docs') . ')');
});

it('does not rewrite URLs inside code spans or code blocks', function (): void {
    $body = <<<'MD'
        Config example:

        ```
        url = https://internal.example
        link = [docs](https://internal.example/docs)
        ```

        See [external](https://external.example) for more.
        MD;

    $cl = new Changelog([makeEntryForSanitize('1.0.0', $body)]);
    $renderer = new SanitizingMarkdownRenderer(
        inner: new MarkdownRenderer(),
        linkRewriter: fn (string $url): string => 'REWRITTEN',
    );
    $out = $renderer->render($cl);

    expect($out)->toContain('https://internal.example/docs'); // inside code block, untouched
    expect($out)->toContain('[external](REWRITTEN)');         // outside, rewritten
});

it('rewrites only http(s) URLs, leaves other schemes alone', function (): void {
    $cl = new Changelog([
        makeEntryForSanitize('1.0.0', '[mailto](mailto:foo@bar) and [http](http://x.test).'),
    ]);

    $renderer = new SanitizingMarkdownRenderer(
        inner: new MarkdownRenderer(),
        linkRewriter: fn (string $url): string => 'REWRITTEN',
    );
    $out = $renderer->render($cl);

    expect($out)->toContain('[mailto](mailto:foo@bar)'); // not rewritten
    expect($out)->toContain('[http](REWRITTEN)');         // rewritten
});

it('passes only the URL to the callback (not the link text)', function (): void {
    $received = [];
    $cl = new Changelog([
        makeEntryForSanitize('1.0.0', '[Click here](https://example.test/a) and [there](https://example.test/b).'),
    ]);

    $renderer = new SanitizingMarkdownRenderer(
        inner: new MarkdownRenderer(),
        linkRewriter: function (string $url) use (&$received): string {
            $received[] = $url;

            return $url;
        },
    );
    $renderer->render($cl);

    expect($received)->toBe(['https://example.test/a', 'https://example.test/b']);
});

// ---------------------------------------------------------------------------
// Combined behaviour
// ---------------------------------------------------------------------------

it('applies both mention stripping and link rewriting in one pass', function (): void {
    $cl = new Changelog([
        makeEntryForSanitize('1.0.0', 'PR by @octocat. See [docs](https://docs.example).'),
    ]);

    $renderer = new SanitizingMarkdownRenderer(
        inner: new MarkdownRenderer(),
        stripMentions: true,
        linkRewriter: fn (string $url): string => 'https://safe.example/?to=' . urlencode($url),
    );
    $out = $renderer->render($cl);

    expect($out)->not->toContain('@octocat');
    expect($out)->toContain('PR by octocat');
    expect($out)->toContain('[docs](https://safe.example/?to=' . urlencode('https://docs.example') . ')');
});

it('passes the inner renderer output through unchanged when no transforms apply', function (): void {
    $cl = new Changelog([
        makeEntryForSanitize('1.0.0', 'Plain text, no mentions, no links.'),
    ]);

    $inner = new MarkdownRenderer();
    $renderer = new SanitizingMarkdownRenderer(
        inner: $inner,
        stripMentions: true,
        linkRewriter: fn (string $url): string => $url,
    );

    expect($renderer->render($cl))->toBe($inner->render($cl));
});
