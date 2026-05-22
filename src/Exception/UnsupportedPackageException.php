<?php

declare(strict_types=1);

namespace n5s\Rangelog\Exception;

/**
 * Thrown when a package is recognised but the underlying source
 * cannot serve a changelog (e.g., a wpackagist-plugin/* slug not
 * found on wp.org — premium plugin with no public source).
 *
 * This signals "we tried and there is nothing public to find",
 * distinct from ChangelogNotFoundException ("no provider matched").
 */
final class UnsupportedPackageException extends ChangelogException
{
}
