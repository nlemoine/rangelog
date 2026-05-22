<?php

declare(strict_types=1);

use Http\Mock\Client as MockClient;
use n5s\Rangelog\Domain\Package;
use n5s\Rangelog\Domain\SourceTypes;
use n5s\Rangelog\Domain\VersionRange;
use n5s\Rangelog\Exception\FetchException;
use n5s\Rangelog\Exception\RateLimitedException;
use n5s\Rangelog\Fetcher\HttpFetcher;
use n5s\Rangelog\SourceProvider\GitHubReleasesResolver;
use n5s\Rangelog\SourceProvider\SourceProviderInterface;
use n5s\Rangelog\Tests\TestSupport\ArrayLogger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;

function loadGithubReleasesFixture(string $name): string
{
    $path = __DIR__ . '/../../Fixtures/github/releases/' . $name;
    $body = file_get_contents($path);
    if ($body === false) {
        throw new LogicException("Missing fixture: {$path}");
    }

    return $body;
}

/**
 * Build a GitHubReleasesResolver wired through a real HttpFetcher sharing a
 * single MockClient. Tests queue responses on the mock for GitHub Releases.
 *
 * @return array{0: MockClient, 1: GitHubReleasesResolver}
 */
function buildGithubReleasesResolver(?ArrayLogger $logger = null): array
{
    $factory = new Psr17Factory();
    $mockClient = new MockClient($factory);
    $fetcher = new HttpFetcher($mockClient, $factory);
    $resolver = new GitHubReleasesResolver($fetcher, logger: $logger);

    return [$mockClient, $resolver];
}

it('is a final class implementing SourceProviderInterface', function (): void {
    $reflection = new ReflectionClass(GitHubReleasesResolver::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->implementsInterface(SourceProviderInterface::class))->toBeTrue();
});

it('supports() returns true when sourceUrl is a github.com URL with a valid repo path', function (): void {
    [$mockClient, $resolver] = buildGithubReleasesResolver();

    expect($resolver->supports(new Package('symfony/console', 'https://github.com/symfony/console')))->toBeTrue();
    expect(count($mockClient->getRequests()))->toBe(0);
});

it('supports() returns false when sourceUrl host is not github.com (non-github URL)', function (): void {
    [$mockClient, $resolver] = buildGithubReleasesResolver();

    expect($resolver->supports(new Package('gitlab/example', 'https://gitlab.com/gitlab/example')))->toBeFalse();
    expect(count($mockClient->getRequests()))->toBe(0);
});

it('supports() returns true when Package sourceUrl is a valid github.com URL (even nonexistent org/repo)', function (): void {
    // Under v2.0 URL-host routing, supports() only checks the URL host — it does NOT
    // probe GitHub or Packagist. A URL with a valid github.com host returns true;
    // the FetchException surfaces downstream in resolve() if the repo does not exist.
    [$mockClient, $resolver] = buildGithubReleasesResolver();

    expect($resolver->supports(new Package('nonexistent/package', 'https://github.com/nonexistent/package')))->toBeTrue();
    expect(count($mockClient->getRequests()))->toBe(0);
});

it('supports() returns false for wordpress.org URL (different host)', function (): void {
    [$mockClient, $resolver] = buildGithubReleasesResolver();

    expect($resolver->supports(new Package('wpackagist-plugin/woocommerce', 'https://wordpress.org/plugins/woocommerce/')))->toBeFalse();
    expect(count($mockClient->getRequests()))->toBe(0);
});

it('supports() is case-insensitive on the host (HTTPS://GITHUB.COM/... passes)', function (): void {
    [$mockClient, $resolver] = buildGithubReleasesResolver();

    expect($resolver->supports(new Package('symfony/console', 'HTTPS://GITHUB.COM/symfony/console')))->toBeTrue();
    expect(count($mockClient->getRequests()))->toBe(0);
});

it('resolve() paginates pages 1..N stopping at first empty page; sets prefetchedBody to concatenated JSON', function (): void {
    [$mockClient, $resolver] = buildGithubReleasesResolver();
    // pagination: page 1 (100), page 2 (100), page 3 (11), page 4 (empty)
    $mockClient->addResponse(new Response(200, [], loadGithubReleasesFixture('symfony-console-page-1.json')));
    $mockClient->addResponse(new Response(200, [], loadGithubReleasesFixture('symfony-console-page-1.json')));
    $mockClient->addResponse(new Response(200, [], loadGithubReleasesFixture('symfony-console-page-4.json')));
    $mockClient->addResponse(new Response(200, [], loadGithubReleasesFixture('empty.json')));

    $source = $resolver->resolve(
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
    expect(count($decoded))->toBe(211); // 100 + 100 + 11

    expect($source->metadata['pages_fetched'])->toBe(3);
    expect($source->metadata['releases_count'])->toBe(211);
    expect($source->metadata['truncation_signal'])->toBeNull();
});

it('resolve() returns Source with url pointing to page-1', function (): void {
    [$mockClient, $resolver] = buildGithubReleasesResolver();
    $mockClient->addResponse(new Response(200, [], loadGithubReleasesFixture('symfony-console-page-4.json')));
    $mockClient->addResponse(new Response(200, [], loadGithubReleasesFixture('empty.json')));

    $source = $resolver->resolve(
        new Package('symfony/console', 'https://github.com/symfony/console'),
        VersionRange::changes('1.0.0', '9.0.0'),
    );

    expect($source->url)->toContain('https://api.github.com/repos/symfony/console/releases');
    expect($source->url)->toContain('page=1');
});

it('resolve() sets truncation_signal when page 10 returns 100 entries (1000 cap hit)', function (): void {
    [$mockClient, $resolver] = buildGithubReleasesResolver();
    for ($i = 0; $i < 10; ++$i) {
        $mockClient->addResponse(new Response(200, [], loadGithubReleasesFixture('page-of-100-template.json')));
    }

    // Use from=0.0.0 so that none of the v0.0.x template entries trigger the
    // early-exit boundary check (v0.0.1 > 0.0.0 — no entry is <= from).
    $source = $resolver->resolve(
        new Package('symfony/console', 'https://github.com/symfony/console'),
        VersionRange::changes('0.0.0', '9.0.0'),
    );

    expect($source->metadata['pages_fetched'])->toBe(10);
    expect($source->metadata['releases_count'])->toBe(1000);
    expect($source->metadata['truncation_signal'])->toBe('github_releases_capped');
});

it('resolve() does NOT set truncation_signal when last page has fewer than 100 entries', function (): void {
    [$mockClient, $resolver] = buildGithubReleasesResolver();
    $mockClient->addResponse(new Response(200, [], loadGithubReleasesFixture('symfony-console-page-4.json'))); // 11 entries
    $mockClient->addResponse(new Response(200, [], loadGithubReleasesFixture('empty.json')));

    $source = $resolver->resolve(
        new Package('symfony/console', 'https://github.com/symfony/console'),
        VersionRange::changes('1.0.0', '9.0.0'),
    );

    expect($source->metadata['truncation_signal'])->toBeNull();
    expect($source->metadata['pages_fetched'])->toBe(1);
    expect($source->metadata['releases_count'])->toBe(11);
});

it('resolve() propagates FetchException on 5xx mid-pagination', function (): void {
    [$mockClient, $resolver] = buildGithubReleasesResolver();
    $mockClient->addResponse(new Response(200, [], loadGithubReleasesFixture('symfony-console-page-1.json')));
    $mockClient->addResponse(new Response(500, [], 'server error'));

    $resolver->resolve(
        new Package('symfony/console', 'https://github.com/symfony/console'),
        VersionRange::changes('1.0.0', '9.0.0'),
    );
})->throws(FetchException::class);

it('resolve() propagates RateLimitedException on 429', function (): void {
    [$mockClient, $resolver] = buildGithubReleasesResolver();
    $mockClient->addResponse(new Response(
        429,
        ['Retry-After' => '60', 'X-RateLimit-Reset' => '1731234567'],
        'rate limited',
    ));

    $resolver->resolve(
        new Package('symfony/console', 'https://github.com/symfony/console'),
        VersionRange::changes('1.0.0', '9.0.0'),
    );
})->throws(RateLimitedException::class);

it('emits debug events per page and info on completion', function (): void {
    $logger = new ArrayLogger();
    [$mockClient, $resolver] = buildGithubReleasesResolver($logger);
    $mockClient->addResponse(new Response(200, [], loadGithubReleasesFixture('symfony-console-page-4.json')));
    $mockClient->addResponse(new Response(200, [], loadGithubReleasesFixture('empty.json')));

    $resolver->resolve(
        new Package('symfony/console', 'https://github.com/symfony/console'),
        VersionRange::changes('1.0.0', '9.0.0'),
    );

    $debug = array_values(array_filter(
        $logger->records,
        fn (array $r): bool => $r['level'] === 'debug' && str_contains($r['message'], 'Fetching releases page'),
    ));
    expect(count($debug))->toBeGreaterThanOrEqual(1);
    expect($debug[0]['context'])->toHaveKey('page');
    expect($debug[0]['context'])->toHaveKey('repo');

    $info = array_values(array_filter(
        $logger->records,
        fn (array $r): bool => $r['level'] === 'info' && $r['message'] === 'Fetched {count} releases from {repo} across {pages} pages',
    ));
    expect(count($info))->toBe(1);
});

it('resolve() stops paginating after the page containing a release with version <= range->from', function (): void {
    [$mockClient, $resolver] = buildGithubReleasesResolver();
    // Page 1: 100 entries descending from 4.9.0 to 3.10.0 (last entry is the boundary).
    $mockClient->addResponse(new Response(200, [], loadGithubReleasesFixture('sparse-page-1.json')));
    // Page 2: queued but MUST NOT be fetched — early-exit on page 1 boundary entry 3.10.0.
    $mockClient->addResponse(new Response(200, [], loadGithubReleasesFixture('sparse-page-2.json')));

    $source = $resolver->resolve(
        new Package('symfony/console', 'https://github.com/symfony/console'),
        VersionRange::changes('3.10.0', '5.0.0'),
    );

    // Early-exit triggered: only 1 page fetched (page 2 was NOT requested).
    expect($source->metadata['pages_fetched'])->toBe(1);
    expect($source->metadata['releases_count'])->toBe(100);
    // 1 HTTP request: 1 releases page only (NO Packagist metadata in v2.0).
    expect(count($mockClient->getRequests()))->toBe(1);
});

it('resolve() INCLUDES the page that triggered the early exit — does not drop boundary entries', function (): void {
    [$mockClient, $resolver] = buildGithubReleasesResolver();
    $mockClient->addResponse(new Response(200, [], loadGithubReleasesFixture('sparse-page-1.json')));
    $mockClient->addResponse(new Response(200, [], loadGithubReleasesFixture('sparse-page-2.json')));

    $source = $resolver->resolve(
        new Package('symfony/console', 'https://github.com/symfony/console'),
        VersionRange::changes('3.10.0', '5.0.0'),
    );

    // prefetchedBody contains all 100 entries from page 1 (boundary entry preserved).
    /** @var string $prefetched */
    $prefetched = $source->prefetchedBody;
    /** @var array<int, array{tag_name: string}> $decoded */
    $decoded = json_decode($prefetched, true, 8, JSON_THROW_ON_ERROR);
    expect(count($decoded))->toBe(100);
    // Last entry must be the boundary entry (3.10.0 — the one that triggered early-exit).
    expect($decoded[99]['tag_name'])->toBe('3.10.0');
});

it('resolve() does NOT early-exit when ALL releases are above range->from', function (): void {
    [$mockClient, $resolver] = buildGithubReleasesResolver();
    // Page 1: 4.9.0 to 3.10.0 — all above from=1.0.0 → no early-exit.
    $mockClient->addResponse(new Response(200, [], loadGithubReleasesFixture('sparse-page-1.json')));
    // Page 2: 3.9.0 to 2.10.0 — still all above from=1.0.0 → no early-exit.
    $mockClient->addResponse(new Response(200, [], loadGithubReleasesFixture('sparse-page-2.json')));
    // Page 3: empty → stops naturally.
    $mockClient->addResponse(new Response(200, [], loadGithubReleasesFixture('empty.json')));

    $source = $resolver->resolve(
        new Package('symfony/console', 'https://github.com/symfony/console'),
        VersionRange::changes('1.0.0', '5.0.0'),
    );

    // No early-exit: pagination walks to the empty page 3.
    expect($source->metadata['pages_fetched'])->toBe(2);
    expect($source->metadata['releases_count'])->toBe(200);
    // 3 requests: page1 + page2 + empty page3 (NO Packagist in v2.0).
    expect(count($mockClient->getRequests()))->toBe(3);
});

it('resolve() tolerates non-semver tag_names in the early-exit boundary check', function (): void {
    [$mockClient, $resolver] = buildGithubReleasesResolver();

    // Inline fixture: mix of semver, malformed string, WP-style date, boundary entry, below-boundary.
    $mixedBody = json_encode([
        ['tag_name' => '3.5.0',    'name' => '3.5.0',    'body' => '', 'published_at' => '2026-01-01T00:00:00Z', 'draft' => false, 'prerelease' => false],
        ['tag_name' => 'v-broken', 'name' => 'v-broken',  'body' => '', 'published_at' => '2026-01-01T00:00:00Z', 'draft' => false, 'prerelease' => false],
        ['tag_name' => '20231015', 'name' => '20231015',  'body' => '', 'published_at' => '2026-01-01T00:00:00Z', 'draft' => false, 'prerelease' => false],
        ['tag_name' => '3.10.0',   'name' => '3.10.0',   'body' => '', 'published_at' => '2026-01-01T00:00:00Z', 'draft' => false, 'prerelease' => false],
        ['tag_name' => '3.0.0',    'name' => '3.0.0',    'body' => '', 'published_at' => '2026-01-01T00:00:00Z', 'draft' => false, 'prerelease' => false],
    ], JSON_THROW_ON_ERROR);

    $mockClient->addResponse(new Response(200, [], $mixedBody));
    // Page 2 queued but MUST NOT be fetched — early-exit triggers on page 1 at 3.10.0.
    $mockClient->addResponse(new Response(200, [], loadGithubReleasesFixture('sparse-page-2.json')));

    // Must not throw — non-semver entries (v-broken, 20231015) are skipped; boundary
    // detected at 3.10.0 (composer/semver UnexpectedValueException caught).
    $source = $resolver->resolve(
        new Package('symfony/console', 'https://github.com/symfony/console'),
        VersionRange::changes('3.10.0', '5.0.0'),
    );

    expect($source->metadata['pages_fetched'])->toBe(1);
    // 1 request: 1 page (page 2 not fetched due to early-exit; no Packagist in v2.0).
    expect(count($mockClient->getRequests()))->toBe(1);
});
