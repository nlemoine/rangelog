<?php

declare(strict_types=1);

namespace n5s\Rangelog\Parser;

use Composer\Semver\VersionParser;
use DateTimeImmutable;
use Exception;
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
 * Parses the GitLab Releases REST API JSON array shape into a Changelog.
 *
 * Input: the JSON body of GET /api/v4/projects/{encodedPath}/releases — a
 * top-level array of release objects. Each release exposes `tag_name`
 * (string), `name` (string), `released_at` (string|null, ISO-8601 with
 * optional fractional seconds, e.g. `2026-04-18T12:00:00.000Z`),
 * `description` (string|null, the markdown release notes), and
 * `_links.self` (string — the canonical release URL). Additional fields
 * (`assets`, `milestones`, `evidences`, `commit`, `author`, `tag_path`,
 * etc.) are ignored without crashing.
 *
 * Behaviour rules:
 *  - Sibling of {@see GitHubReleasesParser}, not a shape-configurable
 *    refactor: GitLab adds assets, milestones, and evidences that GitHub
 *    doesn't have, and the two formats will diverge further.
 *  - Version precedence: `version = tag_name ?? name`. The git tag is the
 *    canonical version reference; `name` is decorative (may be a marketing
 *    title like "GitLab 17.4"). If `tag_name` is present-string-non-empty,
 *    it is used; `name` is never consulted as a fallback for a normalize
 *    failure.
 *  - Non-semver silent skip: `VersionParser::normalize()` is wrapped in
 *    `try/catch (UnexpectedValueException)`. Non-semver `tag_name` values
 *    (date-versions, hash-versions, marketing titles) are silently skipped
 *    with a single `debug` "Skipping non-semver version" log carrying
 *    `{version, source}` context. No exception propagates; no log spam
 *    (one event per skip).
 *  - Date field: `released_at` is parsed via
 *    `new DateTimeImmutable($value)` wrapped in `try/catch (\Exception)`.
 *    GitLab returns `'2026-04-18T12:00:00.000Z'` with milliseconds + `Z`
 *    suffix. `DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, ...)`
 *    rejects the `.000` fractional-seconds component
 *    (ATOM = `Y-m-d\TH:i:sP`, no `.u`). The `DateTimeImmutable` constructor
 *    accepts all ISO-8601 variants including fractional seconds and `Z`
 *    suffix; `\Exception` covers both the PHP 8.3+
 *    `\DateMalformedStringException` and the base `\Exception` from older
 *    PHP. Falls back to `null` on parse failure.
 *  - Body field: `description` is preserved verbatim in
 *    `ChangelogEntry.raw`. `null`/non-string is coerced to empty string.
 *  - sourceUrl: prefer `_links.self` when present-string-non-empty;
 *    otherwise null. Defensive array access on `_links` (`is_array()` /
 *    `is_string()`) guards against schema drift without crashing.
 *  - Schema tolerance: every field read uses defensive `is_array()`/
 *    `is_string()` guards. Unknown fields are ignored without crashing.
 *  - Every entry carries exactly one `ChangelogSection(title: '', lines:
 *    explode("\n", $body))` — same as {@see GitHubReleasesParser}.
 *  - GitLab has NO `draft` field. There is no skip for drafts.
 *  - `range` is accepted but NOT applied here — the authoritative range
 *    boundary is {@see \n5s\Rangelog\Domain\Changelog::filter()}.
 *
 * Security:
 *  - JSON-DoS: `json_decode` is invoked with `depth: 8` and
 *    `JSON_THROW_ON_ERROR`. GitLab responses do not approach depth 8 in
 *    practice (deepest: `assets.links[i]` at depth 4); the cap defends
 *    against malicious upstream payloads.
 *  - Schema drift / tampering: defensive `is_array()`/`is_string()` guards
 *    on every field. Top-level `array_is_list` check; per-entry
 *    `is_array($release)` guard; per-field guards for `tag_name`, `name`,
 *    `released_at`, `description`; `_links` access guarded by
 *    `is_array()`.
 *  - Logger leakage: allowed context keys are `version`, `source`, `count`.
 *    The `description` (body) field NEVER appears in a log context.
 */
final readonly class GitLabReleasesParser implements ChangelogParserInterface
{
    /**
     * Maximum nesting depth accepted by json_decode. GitLab Releases responses
     * have a deepest nested value at `assets.links[i]` (depth 4). The cap of
     * 8 leaves generous headroom while rejecting deeply-nested attacker
     * payloads.
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
                'Invalid GitLab Releases JSON: ' . $e->getMessage(),
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
            throw new ParseException('GitLab Releases JSON did not decode to an array');
        }

        $source = $response->source->url;
        $versionParser = new VersionParser();
        $entries = [];

        foreach ($decoded as $release) {
            if (! \is_array($release)) {
                continue;
            }

            // Precedence: tag_name ?? name. The git tag is the canonical
            // version reference (always semver-shaped for Packagist-installable
            // packages); `name` is decorative and may be a marketing-style title
            // like "GitLab 17.4". If tag_name is present-string-non-empty it is
            // used; name is NEVER consulted as a fallback for a normalize failure.
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

            // released_at: ISO-8601 string with optional fractional seconds and Z
            // suffix (e.g. '2026-04-18T12:00:00.000Z'). Use the DateTimeImmutable
            // constructor which accepts all ISO-8601 variants — createFromFormat
            // with ATOM ('Y-m-d\TH:i:sP') rejects the .000 milliseconds component.
            // Catch \Exception (base class) for PHP 8.3+
            // DateMalformedStringException compatibility. Fallback to null on
            // parse failure mirrors GitHubReleasesParser posture.
            $date = null;
            $publishedField = $release['released_at'] ?? null;
            if (\is_string($publishedField) && $publishedField !== '') {
                try {
                    $date = new DateTimeImmutable($publishedField);
                } catch (Exception) {
                    $date = null;
                }
            }

            // description: string|null — coerce null/non-string to empty string.
            $descField = $release['description'] ?? null;
            $body = \is_string($descField) ? $descField : '';

            // sourceUrl: prefer _links.self when present-string-non-empty;
            // otherwise null. The Releases API URL is NOT a meaningful
            // per-entry sourceUrl — callers checking sourceUrl !== null expect a link
            // to a human-readable release page, not the API endpoint. Defensive array
            // access on _links remains: schema drift cannot crash the parser.
            $sourceField = null;
            $linksField = $release['_links'] ?? null;
            if (\is_array($linksField)) {
                $selfField = $linksField['self'] ?? null;
                if (\is_string($selfField) && $selfField !== '') {
                    $sourceField = $selfField;
                }
            }

            $entries[] = new ChangelogEntry(
                version: $rawVersion,
                date: $date,
                sections: [new ChangelogSection(title: '', lines: explode("\n", $body))],
                raw: $body,
                sourceUrl: $sourceField,
            );
        }

        $this->logger->debug('Parsed {count} entries from {source}', [
            'count' => \count($entries),
            'source' => $source,
        ]);

        return new Changelog($entries);
    }
}
