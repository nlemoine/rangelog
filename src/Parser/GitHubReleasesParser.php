<?php

declare(strict_types=1);

namespace n5s\Rangelog\Parser;

use Composer\Semver\VersionParser;
use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use n5s\Rangelog\Domain\Changelog;
use n5s\Rangelog\Domain\ChangelogEntry;
use n5s\Rangelog\Domain\ChangelogSection;
use n5s\Rangelog\Domain\RawResponse;
use n5s\Rangelog\Domain\VersionRange;
use n5s\Rangelog\Exception\ParseException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use UnexpectedValueException;

/**
 * Parses the GitHub Releases REST API JSON array shape into a Changelog.
 *
 * Input: the JSON body of GET /repos/{owner}/{repo}/releases — a top-level
 * array of release objects. Each release exposes `tag_name` (string, never
 * null), `name` (string|null), `body` (string|null, the markdown
 * release-notes), `published_at` (string|null, ISO-8601 ATOM), `draft`
 * (bool), and `prerelease` (bool).
 *
 * Behaviour rules:
 *  - Version precedence: `version = tag_name ?? name`. The git tag is the
 *    canonical reference (always semver-shaped for Packagist-installable
 *    packages); `name` is decorative and may be a marketing-style title
 *    like `"Public release 3.6.1"` or `"Spring 2024 release"`. Both
 *    candidates still pass `composer/semver`
 *    `VersionParser::normalize()` — otherwise the release is SKIPPED with
 *    a single `debug` "Skipping non-semver version" log event carrying
 *    `{version, source}` context.
 *  - Drafts (`draft === true`) are silently skipped.
 *  - Prereleases (`prerelease === true`) are passed through unchanged.
 *    Filtering by stability is the caller's concern via
 *    {@see \n5s\Rangelog\Domain\Changelog::filter()}.
 *  - Body markdown is preserved verbatim in `ChangelogEntry.raw` (no HTML
 *    sanitization at this layer; the bundled renderer sanitizes before
 *    rendering to HTML).
 *  - Every entry carries exactly one `ChangelogSection(title: '',
 *    lines: explode("\n", $body))`; structured section extraction is not
 *    GitHub Releases' concern.
 *  - Entries are emitted in API-response order — no in-parser sort.
 *  - Top-level JSON that does NOT decode to an array (or is invalid JSON, or
 *    exceeds the depth-8 cap) throws `ParseException`.
 *  - `range` is accepted but NOT applied here — the authoritative range
 *    boundary is {@see \n5s\Rangelog\Domain\Changelog::filter()}.
 *
 * Security:
 *  - Untrusted markdown: the `$body` field flows to `ChangelogEntry.raw`
 *    byte-for-byte. Callers rendering HTML MUST sanitize before output.
 *  - JSON-DoS: `json_decode` is invoked with
 *    `depth: self::JSON_DECODE_DEPTH (=8)` and `JSON_THROW_ON_ERROR`.
 *    GitHub responses never approach this depth in practice; the cap
 *    defends against a malicious upstream serving deeply nested payloads.
 *  - Logger leakage: allowed context keys are `version`, `source`, `count`.
 *    No request/response bodies, no headers, no auth tokens.
 */
final readonly class GitHubReleasesParser implements ChangelogParserInterface
{
    /**
     * Maximum nesting depth accepted by json_decode. GitHub Releases responses
     * are flat arrays of objects whose deepest nested value is the `author`
     * sub-object (depth 3). The cap of 8 leaves generous headroom while
     * rejecting deeply-nested attacker payloads.
     */
    private const int JSON_DECODE_DEPTH = 8;

    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function parse(RawResponse $response, VersionRange $range): Changelog
    {
        try {
            $decoded = json_decode(
                json: $response->body,
                associative: true,
                depth: self::JSON_DECODE_DEPTH,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            throw new ParseException(
                'Invalid GitHub Releases JSON: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        // json_decode($body, true) maps both JSON arrays AND JSON objects to PHP
        // arrays (associative for objects). array_is_list() correctly distinguishes
        // a JSON array (list) from a JSON object (assoc map). An empty top-level
        // array `[]` is a valid zero-result state — array_is_list([]) === true so
        // it passes through and emits Changelog([]).
        if (! \is_array($decoded) || ! array_is_list($decoded)) {
            throw new ParseException('GitHub Releases JSON did not decode to an array');
        }

        $source = $response->source->url;
        $versionParser = new VersionParser();
        $entries = [];

        foreach ($decoded as $release) {
            if (! \is_array($release)) {
                continue;
            }

            // Draft skip: unpublished private releases.
            $draftField = $release['draft'] ?? false;
            if ($draftField === true) {
                continue;
            }

            // Precedence: tag_name ?? name. The git tag is the canonical
            // version reference (always semver-shaped for Packagist-installable
            // packages); `name` is decorative and may be a marketing-style title
            // like "Public release 3.6.1" — using `name` first drops real entries
            // via the non-semver-skip catch below for packages like
            // amnuts/opcache-gui.
            $tagField = $release['tag_name'] ?? null;
            if (\is_string($tagField) && $tagField !== '') {
                $rawVersion = $tagField;
            } else {
                $nameField = $release['name'] ?? null;
                if (! \is_string($nameField)) {
                    continue;
                }
                if ($nameField === '') {
                    continue;
                }
                $rawVersion = $nameField;
            }

            // Non-semver silent-skip with debug log.
            try {
                $versionParser->normalize($rawVersion);
            } catch (UnexpectedValueException) {
                $this->logger->debug('Skipping non-semver version', [
                    'version' => $rawVersion,
                    'source' => $source,
                ]);

                continue;
            }

            // published_at: ISO-8601 ATOM string OR null. createFromFormat
            // returns DateTimeImmutable|false; narrow via instanceof — `?:`
            // would conflate false (bool) with null and is a phpstan
            // strict-rules violation.
            $date = null;
            $publishedField = $release['published_at'] ?? null;
            if (\is_string($publishedField) && $publishedField !== '') {
                $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $publishedField);
                if ($parsed instanceof DateTimeImmutable) {
                    $date = $parsed;
                }
            }

            // body: string|null — coerce null/non-string to empty string.
            $bodyField = $release['body'] ?? null;
            $body = \is_string($bodyField) ? $bodyField : '';

            // Per-entry html_url: the GitHub Releases API returns a canonical
            // human URL for each release (e.g. https://github.com/{owner}/{repo}/releases/tag/{tag}).
            // Prefer it over the paginated API URL ($source) so rendered markdown
            // links land on the release page. Treat as untrusted and fall back to
            // the response source URL on absent/null/empty/non-string shapes.
            $htmlUrl = $source;
            $htmlUrlField = $release['html_url'] ?? null;
            if (\is_string($htmlUrlField) && $htmlUrlField !== '') {
                $htmlUrl = $htmlUrlField;
            }

            $entries[] = new ChangelogEntry(
                version: $rawVersion,
                date: $date,
                sections: [new ChangelogSection(title: '', lines: explode("\n", $body))],
                raw: $body,
                sourceUrl: $htmlUrl,
            );
        }

        $this->logger->debug('Parsed {count} entries from {source}', [
            'count' => \count($entries),
            'source' => $source,
        ]);

        return new Changelog($entries);
    }
}
