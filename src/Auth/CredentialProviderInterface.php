<?php

declare(strict_types=1);

namespace n5s\Rangelog\Auth;

/**
 * Returns HTTP header name → value pairs to add to requests for the given URL.
 *
 * Return value [] means no headers to add for this URL.
 * Map keys are HTTP header names; values are the corresponding header values.
 * Header-name case is preserved as the implementation provides it — PSR-7
 * stores headers case-insensitively at the message layer; downstream
 * AuthorizingFetcher / HttpFetcher handles case normalization.
 *
 * Implementations MUST be `final class`.
 *
 * Security: Implementations MUST NOT inject CRLF sequences into header names
 * or values; the consuming AuthorizingFetcher is responsible for header
 * sanitization at the request boundary.
 */
interface CredentialProviderInterface
{
    /**
     * @return array<string, string>
     */
    public function authorize(string $url): array;
}
