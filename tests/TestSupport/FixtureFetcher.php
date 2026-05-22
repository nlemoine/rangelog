<?php

declare(strict_types=1);

namespace n5s\Rangelog\Tests\TestSupport;

use n5s\Rangelog\Domain\RawResponse;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Exception\FetchException;
use n5s\Rangelog\Fetcher\FetcherInterface;

/**
 * Test stub {@see FetcherInterface} that resolves `Source::$url` against a
 * caller-supplied `URL → fixture-path` map and reads the body bytes from
 * `tests/Fixtures/{relative-path}`.
 *
 * Purpose: the `Rangelog` integration test matrix needs to exercise the
 * FULL resolver chain + the REAL parsers without invoking PSR-18 / HTTP
 * at all. `FixtureFetcher` bypasses the HTTP layer entirely — it
 * implements the FetcherInterface contract by substituting file-I/O for
 * the network call. This is the right abstraction at the orchestrator
 * level: the resolvers under test still exercise their real code paths;
 * only the leaf HTTP boundary is replaced.
 *
 * Content-type inference:
 *   - `.json` → `application/json`
 *   - `.md`   → `text/plain`
 *   - `.txt`  → `text/plain`
 *   - any other extension → `application/octet-stream`
 *
 * Error contract:
 *   - URL not in map → `FetchException(statusCode: 404)`
 *   - Mapped path exists in the array but the file is missing on disk →
 *     `FetchException(statusCode: 404)`
 *
 * Information disclosure note (accepted, severity LOW):
 *   The `$urlToFixturePath` values are joined verbatim under `tests/Fixtures/`
 *   with no `realpath`-validation hardening. Acceptable because the class
 *   is test-only, the trust boundary is the test-suite author, and a caller
 *   passing `../../../etc/passwd` as a fixture path is committing their own
 *   foot-gun. Hardening (`realpath` containment + extension allow-list) is
 *   deferred to the day this class is promoted to `src/Testing/` and
 *   becomes part of the public surface.
 */
final readonly class FixtureFetcher implements FetcherInterface
{
    /**
     * @param array<string, string> $urlToFixturePath Map of `Source::$url`
     *        to fixture path RELATIVE to `tests/Fixtures/`.
     */
    public function __construct(
        private array $urlToFixturePath,
    ) {
    }

    public function fetch(Source $source): RawResponse
    {
        if (!isset($this->urlToFixturePath[$source->url])) {
            throw new FetchException(
                message: "No fixture for URL: {$source->url}",
                statusCode: 404,
            );
        }

        $path = \dirname(__DIR__, 1) . '/Fixtures/' . $this->urlToFixturePath[$source->url];
        // Guard with `is_file` BEFORE the read so PHPUnit's `failOnWarning="true"`
        // setting does not promote a `file_get_contents` open-stream warning to
        // a test failure. The double check (is_file + `=== false`) keeps PHPStan
        // max happy since `file_get_contents` is typed `string|false`.
        if (!is_file($path)) {
            throw new FetchException(
                message: "Fixture file missing on disk: {$path}",
                statusCode: 404,
            );
        }
        $body = file_get_contents($path);
        if ($body === false) {
            throw new FetchException(
                message: "Fixture file missing on disk: {$path}",
                statusCode: 404,
            );
        }

        $contentType = match (pathinfo($path, \PATHINFO_EXTENSION)) {
            'json' => 'application/json',
            'md', 'txt' => 'text/plain',
            default => 'application/octet-stream',
        };

        return new RawResponse(
            body: $body,
            contentType: $contentType,
            source: $source,
        );
    }
}
