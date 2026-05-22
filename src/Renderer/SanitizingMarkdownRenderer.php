<?php

declare(strict_types=1);

namespace n5s\Rangelog\Renderer;

use Closure;
use InvalidArgumentException;
use n5s\Rangelog\Domain\Changelog;

/**
 * Renderer decorator that applies markdown-level sanitization transforms to
 * the inner renderer's output. Two transforms, each independently opt-in:
 *
 *  - **Mention stripping** — `@user` and `@org/team` patterns outside code
 *    blocks/spans have their leading `@` removed (`@octocat` → `octocat`).
 *    This prevents a malicious upstream changelog from notifying users when
 *    the rendered output lands in a context that processes mentions
 *    (GitHub PR bodies, Slack, etc.).
 *
 *  - **Link rewriting** — http/https URLs in standard markdown links
 *    `[text](url)` outside code blocks/spans are passed through a
 *    caller-supplied callable. The caller decides the policy (allowlist,
 *    interstitial redirector, no-op). Other URL schemes (`mailto:`,
 *    `tel:`, fragment-only) and reference-style links are left alone.
 *
 * At least one transform MUST be configured; constructing with neither
 * raises `InvalidArgumentException`.
 *
 * IMPLEMENTATION NOTE: this is a regex-based pass, not an AST walker. The
 * underlying `league/commonmark` ships only an HTML renderer, so AST-walk-
 * then-re-render would require writing a markdown output renderer
 * (~200 LOC of per-node logic). Regex covers the standard patterns
 * (fenced code blocks ```/~~~, backtick code spans, ATX-style links)
 * which is sufficient for sanitizing the markdown this library emits.
 *
 * KNOWN LIMITATIONS:
 *  - Reference-style links (`[text][label]` + `[label]: url` elsewhere)
 *    are not rewritten. Standard inline links are.
 *  - Indented code blocks (4-space prefix) are NOT treated as code; only
 *    fenced blocks are. Our parsers do not emit indented code blocks in
 *    `$entry->raw`, so this is an accepted limit.
 *  - Inline HTML (`<a href="...">`) is not rewritten.
 *  - Autolinks (`<https://...>`) are not rewritten.
 *
 * If those gaps matter for your threat model, write a custom renderer
 * that AST-walks via league/commonmark and ships its own per-node
 * markdown emitter.
 */
final readonly class SanitizingMarkdownRenderer implements RendererInterface
{
    /**
     * Captures fenced code blocks (``` and ~~~) and backtick code spans so
     * mention/link patterns inside them can be skipped during sanitization.
     *
     * Order matters: fenced blocks must be matched first because they can
     * legitimately contain backticks.
     */
    private const string CODE_MASK_PATTERN = '/(?:^|\n)([ \t]*)(```|~~~)[^\n]*\n.*?\n\1\2(?=$|\n)|`+[^`\n]+`+/s';

    /**
     * Mentions: `@user` or `@org/team`. Word boundary BEFORE the @ ensures
     * we don't strip the `@` in email-like patterns (`user@host`).
     */
    private const string MENTION_PATTERN = '/(?<!\w)@([A-Za-z0-9][A-Za-z0-9-]*(?:\/[A-Za-z0-9][A-Za-z0-9-]*)?)/';

    /**
     * Standard markdown inline links `[text](url)` with optional title.
     * URL group does not allow whitespace or unescaped close-paren.
     */
    private const string LINK_PATTERN = '/(\[[^\]\n]*\])\((https?:\/\/[^\s)]+)(\s+"[^"]*")?\)/';

    /** @var (Closure(string): string)|null */
    private ?Closure $linkRewriter;

    /**
     * @param (callable(string): string)|null $linkRewriter Callable receiving each link URL
     *        and returning the rewritten URL. `null` disables link rewriting.
     */
    public function __construct(
        private RendererInterface $inner,
        private bool $stripMentions = false,
        ?callable $linkRewriter = null,
    ) {
        if (! $stripMentions && $linkRewriter === null) {
            throw new InvalidArgumentException(
                'SanitizingMarkdownRenderer requires at least one transform: '
                . 'set stripMentions=true and/or pass a linkRewriter.',
            );
        }

        $this->linkRewriter = $linkRewriter !== null ? Closure::fromCallable($linkRewriter) : null;
    }

    public function render(Changelog $changelog): string
    {
        $output = $this->inner->render($changelog);

        return $this->sanitize($output);
    }

    private function sanitize(string $markdown): string
    {
        // Split the input into alternating "code" and "non-code" segments.
        // Code segments are emitted verbatim; non-code segments get the
        // mention/link transforms.
        $segments = $this->splitOnCode($markdown);

        $result = '';
        foreach ($segments as [$isCode, $text]) {
            if ($isCode) {
                $result .= $text;
                continue;
            }

            if ($this->stripMentions) {
                $text = preg_replace(self::MENTION_PATTERN, '$1', $text) ?? $text;
            }

            if ($this->linkRewriter instanceof Closure) {
                $rewriter = $this->linkRewriter;
                $text     = preg_replace_callback(
                    self::LINK_PATTERN,
                    static function (array $m) use ($rewriter): string {
                        $rewritten = $rewriter($m[2]);
                        $title     = $m[3] ?? '';

                        return $m[1] . '(' . $rewritten . $title . ')';
                    },
                    $text,
                ) ?? $text;
            }

            $result .= $text;
        }

        return $result;
    }

    /**
     * Split markdown into [isCode, text] segments. Code segments (fenced
     * blocks + backtick spans) are detected by {@see CODE_MASK_PATTERN};
     * everything else is non-code.
     *
     * @return list<array{0: bool, 1: string}>
     */
    private function splitOnCode(string $markdown): array
    {
        $segments = [];
        $offset   = 0;
        $length   = \strlen($markdown);

        if (preg_match_all(self::CODE_MASK_PATTERN, $markdown, $matches, \PREG_OFFSET_CAPTURE) === false) {
            return [[false, $markdown]];
        }

        foreach ($matches[0] as $match) {
            /** @var array{0: string, 1: int<-1, max>} $match */
            $codeText  = $match[0];
            $codeStart = $match[1];

            if ($codeStart > $offset) {
                $segments[] = [false, substr($markdown, $offset, $codeStart - $offset)];
            }

            $segments[] = [true, $codeText];
            $offset     = $codeStart + \strlen($codeText);
        }

        if ($offset < $length) {
            $segments[] = [false, substr($markdown, $offset)];
        }

        return $segments;
    }
}
