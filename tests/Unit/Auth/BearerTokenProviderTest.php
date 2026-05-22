<?php

declare(strict_types=1);

use n5s\Rangelog\Auth\BearerTokenProvider;
use n5s\Rangelog\Auth\CredentialProviderInterface;

it('is a final class implementing CredentialProviderInterface', function (): void {
    $r = new ReflectionClass(BearerTokenProvider::class);

    expect($r->isFinal())->toBeTrue();
    expect($r->implementsInterface(CredentialProviderInterface::class))->toBeTrue();
});

it('returns an Authorization: Bearer header for any URL', function (): void {
    $provider = new BearerTokenProvider('ghp_xxx');

    expect($provider->authorize('https://api.github.com/foo'))
        ->toBe(['Authorization' => 'Bearer ghp_xxx']);
    expect($provider->authorize('https://attacker.example/foo'))
        ->toBe(['Authorization' => 'Bearer ghp_xxx']);
});

it('rejects an empty or whitespace-only token at construction', function (string $bad): void {
    expect(fn (): BearerTokenProvider => new BearerTokenProvider($bad))
        ->toThrow(InvalidArgumentException::class);
})->with(['', ' ', "\t", "\n"]);
