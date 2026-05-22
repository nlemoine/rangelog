<?php

declare(strict_types=1);

namespace n5s\Rangelog\Domain;

use InvalidArgumentException;

/**
 * Package value object — URL-First API.
 *
 * Stores a (name, sourceUrl) pair.
 *
 * `name` is any non-empty string without leading or trailing whitespace
 * (any ecosystem: npm `react`, scoped `@scope/pkg`, Cargo `serde`,
 * Go-module `gopkg.in/yaml.v3`, Maven `com.foo:bar`, Composer
 * `vendor/package`).
 *
 * `sourceUrl` is a parseable `http://` or `https://` URL stored exactly
 * as provided — no normalization (lowercase host, trailing-slash strip,
 * etc.) is applied at this layer. Both fields are public readonly via
 * constructor promotion.
 *
 * Invalid inputs throw raw `\InvalidArgumentException` as a programmer-error
 * sentinel, NOT a `ChangelogException` subtype.
 */
final readonly class Package
{
    public function __construct(
        public string $name,
        public string $sourceUrl,
    ) {
        // Name validation — non-empty AND no leading/trailing whitespace (no silent trim).
        if ($name === '' || $name !== trim($name)) {
            throw new InvalidArgumentException(
                "Package name must be non-empty with no leading or trailing whitespace: '{$name}'",
            );
        }

        // URL validation — three-condition gate:
        // 1. filter_var validates URL syntax
        // 2. strtolower(scheme) must be http or https (parse_url preserves case)
        // 3. host must be non-empty (covers file://, mailto:, https:// with no host)
        $scheme = parse_url($sourceUrl, \PHP_URL_SCHEME);
        $host = parse_url($sourceUrl, \PHP_URL_HOST);

        if (
            filter_var($sourceUrl, \FILTER_VALIDATE_URL) === false
            || ! \in_array(strtolower(\is_string($scheme) ? $scheme : ''), ['http', 'https'], true)
            || $host === false || $host === null || $host === ''
        ) {
            throw new InvalidArgumentException(
                "Package sourceUrl must be a parseable http(s):// URL: '{$sourceUrl}'",
            );
        }
    }
}
