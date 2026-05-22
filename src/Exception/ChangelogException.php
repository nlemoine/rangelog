<?php

declare(strict_types=1);

namespace n5s\Rangelog\Exception;

use RuntimeException;

/**
 * Base type for every exception thrown by n5s/rangelog.
 *
 * Catch this to handle any library failure; catch a specific subtype
 * for targeted recovery (rate-limit backoff, fallback resolver, etc.).
 *
 * This class is intentionally abstract — the concrete subtypes are top-level
 * siblings, not nested.
 */
abstract class ChangelogException extends RuntimeException
{
}
