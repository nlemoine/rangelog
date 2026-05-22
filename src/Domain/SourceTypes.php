<?php

declare(strict_types=1);

namespace n5s\Rangelog\Domain;

/**
 * Built-in source-type identifiers.
 *
 * Use these constants when constructing Source objects from library
 * code or from caller code that opts into the built-in providers:
 *
 *   new Source(type: SourceTypes::GITHUB_RELEASES, url: ...)
 *
 * Callers wiring custom SourceProviders may use any string discriminator
 * — these are conventions, not a closed set.
 *
 * This class is a constants holder, not a value object. It is final
 * with a private constructor to prevent instantiation.
 */
final class SourceTypes
{
    public const string GITHUB_RELEASES = 'github_releases';
    public const string GITHUB_FILE     = 'github_file';
    public const string GITLAB_RELEASES = 'gitlab_releases';
    public const string GITLAB_FILE     = 'gitlab_file';
    public const string WORDPRESS_ORG   = 'wordpress_org';
    public const string MARKDOWN_URL    = 'markdown_url';

    private function __construct()
    {
    }
}
