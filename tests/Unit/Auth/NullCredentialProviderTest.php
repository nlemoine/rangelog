<?php

declare(strict_types=1);

use n5s\Rangelog\Auth\CredentialProviderInterface;
use n5s\Rangelog\Auth\NullCredentialProvider;

it('is a final readonly class implementing CredentialProviderInterface', function (): void {
    $reflection = new ReflectionClass(NullCredentialProvider::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();
    expect($reflection->implementsInterface(CredentialProviderInterface::class))->toBeTrue();
});

it('returns [] for a GitHub URL', function (): void {
    expect((new NullCredentialProvider())->authorize('https://github.com'))
        ->toBe([]);
});

it('returns [] for a GitHub API URL', function (): void {
    expect((new NullCredentialProvider())->authorize('https://api.github.com/repos/foo/bar'))
        ->toBe([]);
});

it('returns [] for a GitLab URL', function (): void {
    expect((new NullCredentialProvider())->authorize('https://gitlab.com/group/proj'))
        ->toBe([]);
});

it('returns [] for an http:// URL', function (): void {
    expect((new NullCredentialProvider())->authorize('http://example.test'))
        ->toBe([]);
});

it('returns [] for an arbitrary string (interface accepts any string)', function (): void {
    expect((new NullCredentialProvider())->authorize('not-even-a-url'))
        ->toBe([]);
});
