<?php

declare(strict_types=1);

namespace n5s\Rangelog\Auth;

use InvalidArgumentException;

/**
 * Returns `Authorization: Bearer {token}` for every URL.
 *
 * **MUST be wrapped in {@see HostScopedCredentialProvider}.** This provider
 * applies the same Bearer token to any URL it is asked about; used in
 * isolation it leaks the token to whichever host the URL points to,
 * including hosts controlled by upstream package metadata. The scoping
 * adapter is the only correct way to use this primitive.
 */
final readonly class BearerTokenProvider implements CredentialProviderInterface
{
    public function __construct(private string $token)
    {
        if (trim($token) === '') {
            throw new InvalidArgumentException('Bearer token must be a non-empty string');
        }
    }

    /**
     * @return array<string, string>
     */
    public function authorize(string $url): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }
}
