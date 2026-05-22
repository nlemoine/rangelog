<?php

declare(strict_types=1);

namespace n5s\Rangelog\Parser;

use n5s\Rangelog\Domain\SourceTypes;
use n5s\Rangelog\Exception\ParseException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Source-type to parser dispatcher.
 *
 * The registry is a passive lookup table: given a `Source::$type` string,
 * it returns the parser registered for that type. It is not itself a
 * parser — callers pull the parser out and call `parse()` on it directly.
 *
 * Parser instances are eagerly constructor-injected. Built-in parsers have
 * lightweight constructors so eager wiring has no observable cost.
 *
 * This class adds no `SourceTypes` constants and does not assume any
 * particular set of keys. Callers may register any string key.
 */
final readonly class ParserRegistry
{
    /**
     * @param array<string, ChangelogParserInterface> $parsers
     *        Map keyed by Source::$type string. Built-in keys ship as
     *        constants on `SourceTypes`; callers may register any string
     *        discriminator (BYO source providers).
     */
    public function __construct(private array $parsers)
    {
    }

    /**
     * Look up the parser registered for the given source-type string.
     *
     * @throws ParseException When no parser is registered for $type.
     */
    public function parserFor(string $type): ChangelogParserInterface
    {
        if (!isset($this->parsers[$type])) {
            throw new ParseException("No parser registered for source type: {$type}");
        }

        return $this->parsers[$type];
    }

    /**
     * Convenience factory returning a registry pre-populated with the
     * built-in parsers, keyed by the six built-in {@see SourceTypes}
     * constants:
     *
     *   - SourceTypes::GITHUB_RELEASES  => GitHubReleasesParser
     *   - SourceTypes::GITHUB_FILE      => MarkdownParser
     *   - SourceTypes::GITLAB_RELEASES  => GitLabReleasesParser
     *   - SourceTypes::GITLAB_FILE      => MarkdownParser  (independent instance)
     *   - SourceTypes::MARKDOWN_URL     => MarkdownParser  (independent instance)
     *   - SourceTypes::WORDPRESS_ORG    => WordPressReadmeParser
     *
     * The optional `$logger` is threaded through to each parser constructor;
     * passing `null` falls back to {@see NullLogger}. The registry itself
     * never emits log events.
     *
     * GITHUB_FILE, GITLAB_FILE, and MARKDOWN_URL each receive an independent
     * {@see MarkdownParser} instance for cleaner ownership; the marginal cost
     * is one extra object allocation per call.
     */
    public static function defaults(?LoggerInterface $logger = null): self
    {
        $logger ??= new NullLogger();

        return new self([
            SourceTypes::GITHUB_RELEASES  => new GitHubReleasesParser($logger),
            SourceTypes::GITHUB_FILE      => new MarkdownParser($logger),
            SourceTypes::GITLAB_RELEASES  => new GitLabReleasesParser($logger),
            SourceTypes::GITLAB_FILE      => new MarkdownParser($logger),
            SourceTypes::MARKDOWN_URL     => new MarkdownParser($logger),
            SourceTypes::WORDPRESS_ORG    => new WordPressReadmeParser($logger),
        ]);
    }
}
