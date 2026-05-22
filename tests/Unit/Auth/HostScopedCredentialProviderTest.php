<?php

declare(strict_types=1);

use n5s\Rangelog\Auth\CredentialProviderInterface;
use n5s\Rangelog\Auth\HostScopedCredentialProvider;
use n5s\Rangelog\Auth\NullCredentialProvider;

/**
 * Minimal in-test CredentialProvider that records the URL it was called with
 * and returns a fixed header map.
 */
final class RecordingProvider implements CredentialProviderInterface
{
    /** @var list<string> */
    public array $calls = [];

    /** @param array<string, string> $headers */
    public function __construct(private readonly array $headers)
    {
    }

    public function authorize(string $url): array
    {
        $this->calls[] = $url;

        return $this->headers;
    }
}

// ---------------------------------------------------------------------------
// Structural
// ---------------------------------------------------------------------------

it('is a final class implementing CredentialProviderInterface', function (): void {
    $r = new ReflectionClass(HostScopedCredentialProvider::class);

    expect($r->isFinal())->toBeTrue();
    expect($r->implementsInterface(CredentialProviderInterface::class))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------

it('delegates to the inner provider matching the URL host', function (): void {
    $github = new RecordingProvider(['Authorization' => 'Bearer gh-token']);
    $gitlab = new RecordingProvider(['PRIVATE-TOKEN' => 'gl-token']);

    $scoped = new HostScopedCredentialProvider([
        'api.github.com' => $github,
        'gitlab.com'     => $gitlab,
    ]);

    expect($scoped->authorize('https://api.github.com/repos/foo/bar'))
        ->toBe(['Authorization' => 'Bearer gh-token']);

    expect($github->calls)->toBe(['https://api.github.com/repos/foo/bar']);
    expect($gitlab->calls)->toBe([]);
});

it('returns [] for hosts not in the map', function (): void {
    $github = new RecordingProvider(['Authorization' => 'Bearer gh-token']);

    $scoped = new HostScopedCredentialProvider([
        'api.github.com' => $github,
    ]);

    expect($scoped->authorize('https://attacker.example/CHANGELOG.md'))->toBe([]);
    expect($github->calls)->toBe([]);
});

it('returns [] for a malformed URL with no parseable host', function (): void {
    $scoped = new HostScopedCredentialProvider([
        'api.github.com' => new RecordingProvider(['x' => '1']),
    ]);

    expect($scoped->authorize('not-a-url'))->toBe([]);
    expect($scoped->authorize(''))->toBe([]);
});

it('matches hosts case-insensitively', function (): void {
    $github = new RecordingProvider(['Authorization' => 'Bearer gh-token']);

    $scoped = new HostScopedCredentialProvider([
        'api.github.com' => $github,
    ]);

    expect($scoped->authorize('https://API.GitHub.COM/repos/foo/bar'))
        ->toBe(['Authorization' => 'Bearer gh-token']);
});

it('normalizes the map keys case-insensitively at construction', function (): void {
    $github = new RecordingProvider(['Authorization' => 'Bearer gh-token']);

    $scoped = new HostScopedCredentialProvider([
        'Api.GitHub.com' => $github,
    ]);

    expect($scoped->authorize('https://api.github.com/repos/foo/bar'))
        ->toBe(['Authorization' => 'Bearer gh-token']);
});

it('ignores port numbers in the URL host', function (): void {
    $internal = new RecordingProvider(['PRIVATE-TOKEN' => 'corp-token']);

    $scoped = new HostScopedCredentialProvider([
        'gitlab.internal.corp' => $internal,
    ]);

    expect($scoped->authorize('https://gitlab.internal.corp:8443/api/v4/projects'))
        ->toBe(['PRIVATE-TOKEN' => 'corp-token']);
});

it('does NOT match subdomains automatically — only exact hosts', function (): void {
    $github = new RecordingProvider(['Authorization' => 'Bearer gh-token']);

    $scoped = new HostScopedCredentialProvider([
        'github.com' => $github,
    ]);

    // Subdomain — must not match.
    expect($scoped->authorize('https://api.github.com/repos/foo/bar'))->toBe([]);
    // Suffix attacker host — must not match either.
    expect($scoped->authorize('https://evilgithub.com/foo'))->toBe([]);
    expect($scoped->authorize('https://github.com.attacker.example/foo'))->toBe([]);
});

it('accepts an empty map and returns [] for every URL', function (): void {
    $scoped = new HostScopedCredentialProvider([]);

    expect($scoped->authorize('https://api.github.com/foo'))->toBe([]);
    expect($scoped->authorize('https://anything.example/foo'))->toBe([]);
});

it('composes with NullCredentialProvider for explicit "no auth on this host"', function (): void {
    $scoped = new HostScopedCredentialProvider([
        'api.github.com' => new NullCredentialProvider(),
    ]);

    expect($scoped->authorize('https://api.github.com/foo'))->toBe([]);
});

// ---------------------------------------------------------------------------
// ::standard() factory
// ---------------------------------------------------------------------------

it('::standard() returns a HostScopedCredentialProvider', function (): void {
    $scoped = HostScopedCredentialProvider::standard(githubToken: 'ghp_x');

    expect($scoped)->toBeInstanceOf(HostScopedCredentialProvider::class);
});

it('::standard() wires api.github.com AND raw.githubusercontent.com when githubToken is set', function (): void {
    $scoped = HostScopedCredentialProvider::standard(githubToken: 'ghp_x');

    expect($scoped->authorize('https://api.github.com/repos/foo/bar'))
        ->toBe(['Authorization' => 'Bearer ghp_x']);
    expect($scoped->authorize('https://raw.githubusercontent.com/foo/bar/main/CHANGELOG.md'))
        ->toBe(['Authorization' => 'Bearer ghp_x']);
});

it('::standard() wires gitlab.com when gitlabToken is set', function (): void {
    $scoped = HostScopedCredentialProvider::standard(gitlabToken: 'glpat-x');

    expect($scoped->authorize('https://gitlab.com/api/v4/projects/1/releases'))
        ->toBe(['PRIVATE-TOKEN' => 'glpat-x']);
});

it('::standard() wires both providers when both tokens are passed', function (): void {
    $scoped = HostScopedCredentialProvider::standard(githubToken: 'ghp_x', gitlabToken: 'glpat-y');

    expect($scoped->authorize('https://api.github.com/foo'))->toBe(['Authorization' => 'Bearer ghp_x']);
    expect($scoped->authorize('https://gitlab.com/foo'))->toBe(['PRIVATE-TOKEN' => 'glpat-y']);
});

it('::standard() returns [] for hosts outside the wired set', function (): void {
    $scoped = HostScopedCredentialProvider::standard(githubToken: 'ghp_x', gitlabToken: 'glpat-y');

    expect($scoped->authorize('https://attacker.example/CHANGELOG.md'))->toBe([]);
    expect($scoped->authorize('https://wordpress.org/plugins/akismet/'))->toBe([]);
    expect($scoped->authorize('https://plugins.svn.wordpress.org/akismet/trunk/readme.txt'))->toBe([]);
});

it('::standard() returns an empty-map provider when no tokens are passed', function (): void {
    $scoped = HostScopedCredentialProvider::standard();

    expect($scoped->authorize('https://api.github.com/foo'))->toBe([]);
    expect($scoped->authorize('https://gitlab.com/foo'))->toBe([]);
    expect($scoped->authorize('https://anything.example/foo'))->toBe([]);
});

it('::standard() rejects an empty/whitespace token (via the underlying primitives)', function (string $bad): void {
    expect(fn (): HostScopedCredentialProvider => HostScopedCredentialProvider::standard(githubToken: $bad))
        ->toThrow(InvalidArgumentException::class);
    expect(fn (): HostScopedCredentialProvider => HostScopedCredentialProvider::standard(gitlabToken: $bad))
        ->toThrow(InvalidArgumentException::class);
})->with(['', ' ', "\t"]);
