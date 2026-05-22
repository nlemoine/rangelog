<?php

declare(strict_types=1);

use n5s\Rangelog\Auth\CredentialProviderInterface;
use n5s\Rangelog\Auth\GitLabTokenProvider;

it('is a final class implementing CredentialProviderInterface', function (): void {
    $r = new ReflectionClass(GitLabTokenProvider::class);

    expect($r->isFinal())->toBeTrue();
    expect($r->implementsInterface(CredentialProviderInterface::class))->toBeTrue();
});

it('returns a PRIVATE-TOKEN header for any URL', function (): void {
    $provider = new GitLabTokenProvider('glpat-xxx');

    expect($provider->authorize('https://gitlab.com/foo'))
        ->toBe(['PRIVATE-TOKEN' => 'glpat-xxx']);
    expect($provider->authorize('https://attacker.example/foo'))
        ->toBe(['PRIVATE-TOKEN' => 'glpat-xxx']);
});

it('rejects an empty or whitespace-only token at construction', function (string $bad): void {
    expect(fn (): GitLabTokenProvider => new GitLabTokenProvider($bad))
        ->toThrow(InvalidArgumentException::class);
})->with(['', ' ', "\t", "\n"]);
