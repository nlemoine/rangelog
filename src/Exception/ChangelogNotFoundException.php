<?php

declare(strict_types=1);

namespace n5s\Rangelog\Exception;

/**
 * Thrown when no source provider in the chain can locate a changelog
 * for the requested package — every provider's supports() returned false,
 * or the resolved Source returned no parseable entries.
 */
final class ChangelogNotFoundException extends ChangelogException
{
}
