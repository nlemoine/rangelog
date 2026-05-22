<?php

declare(strict_types=1);

namespace n5s\Rangelog\Parser;

use n5s\Rangelog\Domain\Changelog;
use n5s\Rangelog\Domain\RawResponse;
use n5s\Rangelog\Domain\VersionRange;
use n5s\Rangelog\Exception\ParseException;

/**
 * Public extension contract — parse a RawResponse into a Changelog.
 *
 * Callers implement this to support new RawResponse formats. The library
 * ships default parsers for Markdown, GitHub Releases JSON, GitLab Releases
 * JSON, and WordPress readme.txt.
 *
 * Implementations:
 *  - parse(RawResponse, VersionRange): Changelog — the VersionRange is
 *    passed for parser-side optimisation hints (e.g. early-exit on
 *    out-of-range versions). The authoritative filter still runs in
 *    {@see \n5s\Rangelog\Domain\Changelog::filter()}, so parsers MAY
 *    return the full set and let the domain handle the boundary.
 *  - On unparseable input, throw ParseException — never return null.
 *  - Wrap composer/semver in try/catch — non-semver versions are skipped
 *    silently, never thrown.
 *
 * Implementations MUST be `final class`.
 */
interface ChangelogParserInterface
{
    /**
     * @throws ParseException When the RawResponse cannot be parsed.
     */
    public function parse(RawResponse $response, VersionRange $range): Changelog;
}
