<?php

declare(strict_types=1);

use Http\Mock\Client as MockClient;
use n5s\Rangelog\Cache\Psr6CacheAdapter;
use n5s\Rangelog\Domain\Package;
use n5s\Rangelog\Domain\SourceTypes;
use n5s\Rangelog\Domain\VersionRange;
use n5s\Rangelog\Exception\ChangelogNotFoundException;
use n5s\Rangelog\Fetcher\CachingFetcher;
use n5s\Rangelog\Fetcher\FetcherInterface;
use n5s\Rangelog\Fetcher\FetcherStack;
use n5s\Rangelog\Fetcher\HttpFetcher;
use n5s\Rangelog\SourceProvider\GitHubFileResolver;
use n5s\Rangelog\SourceProvider\GitHubReleasesResolver;
use n5s\Rangelog\SourceProvider\MarkdownUrlResolver;
use n5s\Rangelog\SourceProvider\SourceProviderChain;
use n5s\Rangelog\SourceProvider\WordPressOrgResolver;
use n5s\Rangelog\Tests\TestSupport\ArrayCachePool;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;

/**
 * Wires the full resolver chain end-to-end (SourceProviderChain over the four
 * concrete resolvers + shared FetcherStack with HttpFetcher + CachingFetcher
 * backed by ArrayCachePool) against php-http/mock-client. No live HTTP.
 * MarkdownUrlResolver is the terminal fallback.
 *
 * @return array{0: SourceProviderChain, 1: MockClient, 2: FetcherInterface}
 */
function buildResolverChainStack(): array
{
    $factory = new Psr17Factory();
    $mockClient = new MockClient($factory);
    $pool = new ArrayCachePool();
    $cache = new Psr6CacheAdapter($pool);

    $fetcher = new FetcherStack(
        base: new HttpFetcher($mockClient, $factory),
        decorators: [
            fn (FetcherInterface $inner): FetcherInterface => new CachingFetcher(
                inner: $inner,
                cache: $cache,
            ),
        ],
    );

    $chain = new SourceProviderChain([
        new WordPressOrgResolver($fetcher),
        new GitHubReleasesResolver($fetcher),
        new GitHubFileResolver($fetcher),
        new MarkdownUrlResolver($fetcher),
    ]);

    return [$chain, $mockClient, $fetcher];
}

function loadChainGithubFixture(string $name): string
{
    $path = __DIR__ . '/../../Fixtures/github/releases/' . $name;
    $body = file_get_contents($path);
    if ($body === false) {
        throw new LogicException("Missing fixture: {$path}");
    }

    return $body;
}

it('resolves a wpackagist-plugin package via WordPressOrgResolver (chain stops at first supports=true)', function (): void {
    [$chain, $mockClient] = buildResolverChainStack();
    $mockClient->addResponse(new Response(200, [], '# Changelog body'));

    $source = $chain->resolve(
        new Package('wpackagist-plugin/woocommerce', 'https://wordpress.org/plugins/woocommerce/'),
        VersionRange::changes('1.0', '9.0.0'),
    );

    expect($source->type)->toBe(SourceTypes::GITHUB_FILE);
    expect($source->url)->toContain('plugins.svn.wordpress.org/woocommerce/tags/9.0.0/changelog.md');
    expect(count($mockClient->getRequests()))->toBe(1);
});

it('resolves a GitHub package via GitHubReleasesResolver (synchronous URL-host check + paginate)', function (): void {
    [$chain, $mockClient] = buildResolverChainStack();
    // GitHubReleasesResolver.supports() is a synchronous URL-host check (no HTTP).
    // GitHubReleasesResolver.resolve() paginates directly — no probe.
    // page=1 fetch:
    $mockClient->addResponse(new Response(200, [], loadChainGithubFixture('symfony-console-page-1.json')));
    // page=2 fetch — empty array stops pagination.
    $mockClient->addResponse(new Response(200, [], loadChainGithubFixture('empty.json')));

    $source = $chain->resolve(
        new Package('symfony/console', 'https://github.com/symfony/console'),
        VersionRange::changes('1.0.0', '9.0.0'),
    );

    expect($source->type)->toBe(SourceTypes::GITHUB_RELEASES);
    expect($source->prefetchedBody)->toBeString();
    /** @var string $prefetched */
    $prefetched = $source->prefetchedBody;
    $decoded = json_decode($prefetched, true, 8, JSON_THROW_ON_ERROR);
    expect(is_array($decoded))->toBeTrue();
    /** @var array<int, mixed> $decoded */
    expect(count($decoded))->toBe(100);
    expect($source->metadata['releases_count'])->toBe(100);
});

it('resolves a GitHub package via GitHubReleasesResolver (returns Source even when releases page is empty)', function (): void {
    // GitHubReleasesResolver.supports() is a synchronous URL-host check — it does not probe
    // the releases API. chain.resolve() calls it first since it precedes GitHubFileResolver.
    // resolve() fetches page=1 → empty array → loop breaks → returns Source with 0 entries.
    // Chain does NOT fall through via resolve(); fall-through is handled by the
    // IterativeSourceProviderInterface path in Rangelog.
    [$chain, $mockClient] = buildResolverChainStack();
    // GitHubReleasesResolver.resolve() page=1 returns empty array → Source with 0 releases.
    $mockClient->addResponse(new Response(200, [], loadChainGithubFixture('empty.json')));

    $source = $chain->resolve(
        new Package('symfony/console', 'https://github.com/symfony/console'),
        VersionRange::changes('1.0.0', '2.0.0'),
    );

    expect($source->type)->toBe(SourceTypes::GITHUB_RELEASES);
    expect($source->metadata['releases_count'])->toBe(0);
});

it('MarkdownUrlResolver handles non-github non-wordpress URL as terminal fallback', function (): void {
    // MarkdownUrlResolver is the terminal fallback. supports() always true.
    // For a URL that GitHub/WordPress resolvers reject, MarkdownUrlResolver fires and fetches.
    // Mock a 404 so resolve() throws ChangelogNotFoundException.
    [$chain, $mockClient] = buildResolverChainStack();
    $mockClient->addResponse(new Response(404, [], 'not found'));

    $caught = null;
    try {
        $chain->resolve(
            new Package('any/package', 'https://example.com/CHANGELOG.md'),
            VersionRange::changes('1.0.0', '2.0.0'),
        );
    } catch (ChangelogNotFoundException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ChangelogNotFoundException::class);
});
