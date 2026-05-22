<?php

declare(strict_types=1);

namespace n5s\Rangelog\Auth;

use InvalidArgumentException;

/**
 * Returns the GitLab `PRIVATE-TOKEN` header for every URL.
 *
 * **MUST be wrapped in {@see HostScopedCredentialProvider}.** This provider
 * applies the same token to any URL; used in isolation it leaks the token
 * to whichever host the URL points to. The scoping adapter is the only
 * correct way to use this primitive.
 */
final readonly class GitLabTokenProvider implements CredentialProviderInterface
{
    public function __construct(private string $token)
    {
        if (trim($token) === '') {
            throw new InvalidArgumentException('GitLab token must be a non-empty string');
        }
    }

    /**
     * @return array<string, string>
     */
    public function authorize(string $url): array
    {
        return ['PRIVATE-TOKEN' => $this->token];
    }
}
