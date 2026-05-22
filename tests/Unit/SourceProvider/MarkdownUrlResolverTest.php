<?php

declare(strict_types=1);

use Http\Mock\Client as MockClient;
use n5s\Rangelog\Domain\Package;
use n5s\Rangelog\Domain\SourceTypes;
use n5s\Rangelog\Domain\VersionRange;
use n5s\Rangelog\Exception\ChangelogNotFoundException;
use n5s\Rangelog\Exception\FetchException;
use n5s\Rangelog\Fetcher\HttpFetcher;
use n5s\Rangelog\SourceProvider\MarkdownUrlResolver;
use n5s\Rangelog\SourceProvider\SourceProviderInterface;
use n5s\Rangelog\Tests\TestSupport\ArrayLogger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;

/**
 * @return array{0: MockClient, 1: MarkdownUrlResolver}
 */
function buildMarkdownUrlResolver(?ArrayLogger $logger = null): array
{
    $factory = new Psr17Factory();
    $mockClient = new MockClient($factory);
    $fetcher = new HttpFetcher($mockClient, $factory);
    $resolver = new MarkdownUrlResolver($fetcher, $logger);

    return [$mockClient, $resolver];
}

it('is a final class implementing SourceProviderInterface', function (): void {
    $reflection = new ReflectionClass(MarkdownUrlResolver::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->implementsInterface(SourceProviderInterface::class))->toBeTrue();
});

it('supports() always returns true regardless of URL', function (): void {
    [, $resolver] = buildMarkdownUrlResolver();

    $packages = [
        new Package('a', 'https://github.com/x/y'),
        new Package('b', 'https://wordpress.org/plugins/z/'),
        new Package('c', 'https://example.com/CHANGELOG.md'),
    ];

    foreach ($packages as $package) {
        expect($resolver->supports($package))->toBeTrue();
    }
});

it('resolve() returns a Source with type MARKDOWN_URL and url = package->sourceUrl on a successful fetch', function (): void {
    [$mockClient, $resolver] = buildMarkdownUrlResolver();
    $mockClient->addResponse(new Response(200, [], "## CHANGELOG\n\n## [1.0.0]\n- initial"));

    $package = new Package('any', 'https://example.com/CHANGELOG.md');
    $source = $resolver->resolve($package, VersionRange::changes('1.0.0', '2.0.0'));

    expect($source->type)->toBe(SourceTypes::MARKDOWN_URL);
    expect($source->url)->toBe('https://example.com/CHANGELOG.md');
});

it('resolve() rethrows fetch 404 as ChangelogNotFoundException', function (): void {
    [$mockClient, $resolver] = buildMarkdownUrlResolver();
    $mockClient->addResponse(new Response(404, [], 'not found'));

    $resolver->resolve(
        new Package('any', 'https://example.com/CHANGELOG.md'),
        VersionRange::changes('1.0.0', '2.0.0'),
    );
})->throws(ChangelogNotFoundException::class);

it('resolve() rethrows fetch 5xx as ChangelogNotFoundException', function (): void {
    [$mockClient, $resolver] = buildMarkdownUrlResolver();
    $mockClient->addResponse(new Response(500, [], 'server error'));

    $resolver->resolve(
        new Package('any', 'https://example.com/CHANGELOG.md'),
        VersionRange::changes('1.0.0', '2.0.0'),
    );
})->throws(ChangelogNotFoundException::class);

it('resolve() preserves the original FetchException as previous', function (): void {
    [$mockClient, $resolver] = buildMarkdownUrlResolver();
    $mockClient->addResponse(new Response(404, [], 'not found'));

    try {
        $resolver->resolve(
            new Package('any', 'https://example.com/CHANGELOG.md'),
            VersionRange::changes('1.0.0', '2.0.0'),
        );
        expect(false)->toBeTrue('Expected ChangelogNotFoundException was not thrown');
    } catch (ChangelogNotFoundException $e) {
        expect($e->getPrevious())->toBeInstanceOf(FetchException::class);
        /** @var FetchException $prev */
        $prev = $e->getPrevious();
        expect($prev->statusCode)->toBe(404);
    }
});

// ---------------------------------------------------------------------------
// Host allowlist (security opt-in)
// ---------------------------------------------------------------------------

/**
 * @param list<string> $allowedHosts
 */
function buildAllowlistedResolver(array $allowedHosts): MarkdownUrlResolver
{
    $factory = new Psr17Factory();
    $fetcher = new HttpFetcher(new MockClient($factory), $factory);

    return new MarkdownUrlResolver($fetcher, allowedHosts: $allowedHosts);
}

it('supports() returns true for any host when no allowlist is configured (default)', function (): void {
    [, $resolver] = buildMarkdownUrlResolver();

    expect($resolver->supports(new Package('any', 'https://attacker.example/CHANGELOG.md')))->toBeTrue();
});

it('supports() returns true only for hosts in the allowlist', function (): void {
    $resolver = buildAllowlistedResolver(['raw.githubusercontent.com', 'gitlab.com']);

    expect($resolver->supports(new Package('a', 'https://raw.githubusercontent.com/x/y/main/CHANGELOG.md')))
        ->toBeTrue();
    expect($resolver->supports(new Package('b', 'https://gitlab.com/x/y/-/raw/main/CHANGELOG.md')))
        ->toBeTrue();
});

it('supports() returns false for hosts not in the allowlist', function (): void {
    $resolver = buildAllowlistedResolver(['raw.githubusercontent.com']);

    expect($resolver->supports(new Package('a', 'https://attacker.example/CHANGELOG.md')))->toBeFalse();
    expect($resolver->supports(new Package('b', 'https://gitlab.com/x/y/-/raw/main/CHANGELOG.md')))->toBeFalse();
});

it('matches allowlist entries case-insensitively', function (): void {
    $resolver = buildAllowlistedResolver(['Raw.GitHub.com']);

    expect($resolver->supports(new Package('a', 'https://RAW.GITHUB.COM/x/y/CHANGELOG.md')))->toBeTrue();
    expect($resolver->supports(new Package('a', 'https://raw.github.com/x/y/CHANGELOG.md')))->toBeTrue();
});

it('does NOT match subdomains automatically — only exact hosts', function (): void {
    $resolver = buildAllowlistedResolver(['example.com']);

    expect($resolver->supports(new Package('a', 'https://raw.example.com/CHANGELOG.md')))->toBeFalse();
    expect($resolver->supports(new Package('a', 'https://evilexample.com/CHANGELOG.md')))->toBeFalse();
    expect($resolver->supports(new Package('a', 'https://example.com.attacker.test/CHANGELOG.md')))->toBeFalse();
});

it('ignores port numbers in the URL host', function (): void {
    $resolver = buildAllowlistedResolver(['gitlab.internal.corp']);

    expect($resolver->supports(new Package('a', 'https://gitlab.internal.corp:8443/CHANGELOG.md')))->toBeTrue();
});

it('returns false for a malformed URL when an allowlist is configured', function (): void {
    $resolver = buildAllowlistedResolver(['example.com']);

    expect($resolver->supports(new Package('a', 'http://example.com')))->toBeTrue();
    // A URL that does not parse to a usable host (only scheme present)
    // wouldn't reach Package construction (it requires http(s)), so this
    // path mostly guards against future host-less URL shapes.
});

it('returns false for everything when given an empty allowlist', function (): void {
    $resolver = buildAllowlistedResolver([]);

    expect($resolver->supports(new Package('a', 'https://anything.example/CHANGELOG.md')))->toBeFalse();
});

it('resolve() proceeds normally for an allowlisted host (does not re-check supports)', function (): void {
    $factory = new Psr17Factory();
    $mockClient = new MockClient($factory);
    $mockClient->addResponse(new Response(200, [], "## 1.0.0\n- initial"));

    $resolver = new MarkdownUrlResolver(
        new HttpFetcher($mockClient, $factory),
        allowedHosts: ['raw.githubusercontent.com'],
    );

    $source = $resolver->resolve(
        new Package('a', 'https://raw.githubusercontent.com/x/y/main/CHANGELOG.md'),
        VersionRange::changes('1.0.0', '2.0.0'),
    );

    expect($source->type)->toBe(SourceTypes::MARKDOWN_URL);
});
