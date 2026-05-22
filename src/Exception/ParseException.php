<?php

declare(strict_types=1);

namespace n5s\Rangelog\Exception;

/**
 * Thrown by ChangelogParserInterface implementations when the input
 * RawResponse cannot be parsed into a Changelog (malformed JSON,
 * missing expected sections, encoding errors, etc.).
 */
final class ParseException extends ChangelogException
{
}
