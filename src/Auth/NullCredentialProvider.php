<?php

declare(strict_types=1);

namespace n5s\Rangelog\Auth;

final readonly class NullCredentialProvider implements CredentialProviderInterface
{
    /**
     * @return array<string, string>
     */
    public function authorize(string $url): array
    {
        return [];
    }
}
