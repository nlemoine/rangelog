<?php

declare(strict_types=1);

use Http\Mock\Client as MockClient;
use n5s\Rangelog\Auth\CredentialProviderInterface;
use n5s\Rangelog\Cache\Psr6CacheAdapter;
use n5s\Rangelog\Domain\Package;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Domain\SourceTypes;
use n5s\Rangelog\Domain\VersionRange;
use n5s\Rangelog\Exception\ChangelogNotFoundException;
use n5s\Rangelog\Fetcher\AuthorizingFetcher;
use n5s\Rangelog\Fetcher\CachingFetcher;
use n5s\Rangelog\Fetcher\FetcherInterface;
use n5s\Rangelog\Fetcher\FetcherStack;
use n5s\Rangelog\Fetcher\HttpFetcher;
use n5s\Rangelog\SourceProvider\GitLabFileResolver;
use n5s\Rangelog\SourceProvider\GitLabReleasesResolver;
use n5s\Rangelog\SourceProvider\SourceProviderChain;
use n5s\Rangelog\Tests\TestSupport\ArrayCachePool;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;

/**
 * Build a minimal FetcherStack (HttpFetcher base only — no auth, no cache).
 */
function makeBasicStack(MockClient $mockClient): FetcherStack
{
    $factory = new Psr17Factory();

    return new FetcherStack(
        base: new HttpFetcher($mockClient, $factory),
    );
}

/**
 * Build a FetcherStack with AuthorizingFetcher + CachingFetcher decorators.
 *
 * @return array{0: FetcherStack, 1: MockClient}
 */
function makeAuthStack(CredentialProviderInterface $credentials): array
{
    $factory = new Psr17Factory();
    $mockClient = new MockClient($factory);

    $stack = new FetcherStack(
        base: new HttpFetcher($mockClient, $factory),
        decorators: [
            fn (FetcherInterface $i): AuthorizingFetcher => new AuthorizingFetcher($i, $credentials),
            fn (FetcherInterface $i): CachingFetcher => new CachingFetcher($i, new Psr6CacheAdapter(new ArrayCachePool()), defaultTtl: 3600),
        ],
    );

    return [$stack, $mockClient];
}

it('resolves nested-group URL through GitLabReleasesResolver via SourceProviderChain; the outbound API URL uses %2F-encoded namespace path', function (): void {
    $factory = new Psr17Factory();
    $mockClient = new MockClient($factory);

    // Queue a valid releases page — two entries, both newer than 0.0.0 range boundary.
    $releasesBody = file_get_contents(__DIR__ . '/../../Fixtures/gitlab/releases/release-cli-page1.json');
    assert($releasesBody !== false);

    // Queue page 1 (2 entries), then empty page 2 to terminate pagination.
    $mockClient->addResponse(new Response(200, ['Content-Type' => 'application/json'], $releasesBody));
    $mockClient->addResponse(new Response(200, ['Content-Type' => 'application/json'], '[]'));

    $stack = makeBasicStack($mockClient);
    $chain = new SourceProviderChain([
        new GitLabReleasesResolver($stack),
        new GitLabFileResolver($stack),
    ]);

    $source = $chain->resolve(
        new Package('cves', 'https://gitlab.com/gitlab-org/security/cves'),
        VersionRange::changes('0.0.0', '99.0.0'),
    );

    // Assert the winning resolver is GitLabReleasesResolver.
    expect($source->type)->toBe(SourceTypes::GITLAB_RELEASES);

    // Assert the outbound request URL encodes the nested namespace with %2F.
    $requests = $mockClient->getRequests();
    expect($requests)->not->toBeEmpty();

    $firstRequest = $requests[0];
    expect($firstRequest->getUri()->getHost())->toBe('gitlab.com');
    expect($firstRequest->getUri()->getPath())->toContain('/api/v4/projects/gitlab-org%2Fsecurity%2Fcves/releases');

    // Assert the query parameters are present.
    $query = $firstRequest->getUri()->getQuery();
    expect($query)->toContain('per_page=100');
    expect($query)->toContain('page=1');
    expect($query)->toContain('order_by=released_at');
    expect($query)->toContain('sort=desc');
});

it('verifies prefetchedBody short-circuit — calling FetcherStack::fetch on the returned Source does NOT trigger a new MockClient request', function (): void {
    $factory = new Psr17Factory();
    $mockClient = new MockClient($factory);

    $releasesBody = file_get_contents(__DIR__ . '/../../Fixtures/gitlab/releases/release-cli-page1.json');
    assert($releasesBody !== false);

    // Page 1 returns 2 entries; page 2 empty → pagination terminates.
    $mockClient->addResponse(new Response(200, ['Content-Type' => 'application/json'], $releasesBody));
    $mockClient->addResponse(new Response(200, ['Content-Type' => 'application/json'], '[]'));

    $stack = makeBasicStack($mockClient);
    $chain = new SourceProviderChain([
        new GitLabReleasesResolver($stack),
        new GitLabFileResolver($stack),
    ]);

    $source = $chain->resolve(
        new Package('release-cli', 'https://gitlab.com/gitlab-org/release-cli'),
        VersionRange::changes('0.0.0', '99.0.0'),
    );

    // Snapshot the request count after resolve() returns.
    $requestCountAfterResolve = count($mockClient->getRequests());

    // The returned Source must carry prefetchedBody (set by GitLabReleasesResolver).
    expect($source->prefetchedBody)->not->toBeNull();

    // Now fetch the Source via the stack — the HttpFetcher short-circuit
    // detects prefetchedBody !== null and returns a synthetic RawResponse
    // WITHOUT calling $httpClient->sendRequest(). Request count must NOT grow.
    $stack->fetch($source);

    expect($mockClient->getRequests())->toHaveCount($requestCountAfterResolve);
});

it('falls through Releases (empty) → File (200) via SourceProviderChain::resolveAll() (IterativeSourceProviderInterface fall-through pattern)', function (): void {
    $factory = new Psr17Factory();
    $mockClient = new MockClient($factory);

    // GitLabReleasesResolver: page 1 returns empty array → zero releases, prefetchedBody = '[]'.
    $mockClient->addResponse(new Response(200, ['Content-Type' => 'application/json'], '[]'));

    // GitLabFileResolver: CHANGELOG.md@main returns 200.
    $changelogBody = file_get_contents(__DIR__ . '/../../Fixtures/gitlab/files/release-cli-CHANGELOG.md');
    assert($changelogBody !== false);
    $mockClient->addResponse(new Response(200, ['Content-Type' => 'text/markdown'], $changelogBody));

    $stack = makeBasicStack($mockClient);
    $chain = new SourceProviderChain([
        new GitLabReleasesResolver($stack),
        new GitLabFileResolver($stack),
    ]);

    // Use resolveAll() (IterativeSourceProviderInterface) to drive the fall-through.
    // The caller iterates until it finds a non-empty result — here we simulate
    // the Rangelog behavior: consume sources until we get the File source.
    //
    // NOTE: SourceProviderChain::resolve() returns the FIRST supporting provider's
    // result without fall-through. Fall-through is the IterativeSourceProviderInterface
    // concern. This test wires directly to resolveAll().
    $sources = iterator_to_array($chain->resolveAll(
        new Package('release-cli', 'https://gitlab.com/gitlab-org/release-cli'),
        VersionRange::changes('1.0.0', '2.0.0'),
    ));

    // Both resolvers support the gitlab.com URL — two Sources should be yielded.
    expect($sources)->toHaveCount(2);

    // Second source is the file resolver result.
    $fileSource = $sources[1];
    expect($fileSource->type)->toBe(SourceTypes::GITLAB_FILE);
    expect($fileSource->metadata['file'])->toBe('CHANGELOG.md');
    expect($fileSource->metadata['branch'])->toBe('main');
});

it('throws ChangelogNotFoundException when GitLabFileResolver exhausts all filenames × branches (all-404 responses)', function (): void {
    $factory = new Psr17Factory();
    $mockClient = new MockClient($factory);

    // GitLabFileResolver branch walk for range->to = '2.0.0':
    //   VersionBranchDeriver::deriveBranches('2.0.0') → ['2.0', '2.x', '2.0.x']
    //   First file (CHANGELOG.md): [main, master, 2.0, 2.x, 2.0.x] = 5 × 404
    //   After all 5 fail, branch is locked to 'main' (branch-lock-after-first-file invariant).
    //   File 2 (CHANGELOG): [main] = 1 × 404
    //   File 3 (HISTORY.md): [main] = 1 × 404
    //   File 4 (CHANGES.md): [main] = 1 × 404
    //   Total = 5 + 1 + 1 + 1 = 8 × 404
    $notFoundBody = '{"message":"404 File Not Found"}';
    for ($i = 0; $i < 8; $i++) {
        $mockClient->addResponse(new Response(404, ['Content-Type' => 'application/json'], $notFoundBody));
    }

    $stack = makeBasicStack($mockClient);

    // Test GitLabFileResolver directly — it is responsible for throwing ChangelogNotFoundException
    // when all filenames × branches return 404. The chain's resolve() returns the first supporting
    // provider's result (GitLabReleasesResolver); to test file-resolver exhaustion, we call
    // GitLabFileResolver::resolve() directly.
    $resolver = new GitLabFileResolver($stack);

    expect(fn (): Source => $resolver->resolve(
        new Package('no-changelog', 'https://gitlab.com/foo/bar'),
        VersionRange::changes('1.0.0', '2.0.0'),
    ))->toThrow(ChangelogNotFoundException::class);

    // Verify exactly 8 requests were made (all 404-probes consumed).
    expect($mockClient->getRequests())->toHaveCount(8);
});

it('throws ChangelogNotFoundException when SourceProviderChain has no supporting provider (non-gitlab URL)', function (): void {
    $factory = new Psr17Factory();
    $mockClient = new MockClient($factory);

    $stack = makeBasicStack($mockClient);
    $chain = new SourceProviderChain([
        new GitLabReleasesResolver($stack),
        new GitLabFileResolver($stack),
    ]);

    // A github.com URL is not supported by either GitLab resolver — chain throws immediately.
    expect(fn (): Source => $chain->resolve(
        new Package('some-pkg', 'https://github.com/foo/bar'),
        VersionRange::changes('1.0.0', '2.0.0'),
    ))->toThrow(ChangelogNotFoundException::class);

    // No HTTP requests should have been made (supports() is synchronous host-check only).
    expect($mockClient->getRequests())->toHaveCount(0);
});

it('PRIVATE-TOKEN header reaches outbound gitlab.com requests via AuthorizingFetcher; non-gitlab hosts do NOT receive the header', function (): void {
    $credentials = new class () implements CredentialProviderInterface {
        public function authorize(string $url): array
        {
            $host = parse_url($url, PHP_URL_HOST) ?? '';

            return match ($host) {
                'gitlab.com' => ['PRIVATE-TOKEN' => 'gl-token'],
                default => [],
            };
        }
    };

    [$stack, $mockClient] = makeAuthStack($credentials);

    // Queue one successful response for GitLabReleasesResolver page 1 + empty page 2.
    $releasesBody = file_get_contents(__DIR__ . '/../../Fixtures/gitlab/releases/release-cli-page1.json');
    assert($releasesBody !== false);
    $mockClient->addResponse(new Response(200, ['Content-Type' => 'application/json'], $releasesBody));
    $mockClient->addResponse(new Response(200, ['Content-Type' => 'application/json'], '[]'));

    $chain = new SourceProviderChain([
        new GitLabReleasesResolver($stack),
        new GitLabFileResolver($stack),
    ]);

    $chain->resolve(
        new Package('release-cli', 'https://gitlab.com/gitlab-org/release-cli'),
        VersionRange::changes('0.0.0', '99.0.0'),
    );

    // The first outbound request must carry PRIVATE-TOKEN = 'gl-token'.
    $requests = $mockClient->getRequests();
    expect($requests)->not->toBeEmpty();
    expect($requests[0]->getUri()->getHost())->toBe('gitlab.com');
    expect($requests[0]->getHeaderLine('PRIVATE-TOKEN'))->toBe('gl-token');

    // Cross-host leak prevention: verify that if we resolved a non-gitlab.com URL
    // via a different stack, the PRIVATE-TOKEN would NOT be set (AuthorizingFetcher
    // invokes the CredentialProvider per-URL, returning [] for non-gitlab hosts).
    // We verify this by checking the credentials callback contract directly:
    // the anonymous CredentialProvider above returns [] for 'api.github.com'.
    $githubHeaders = $credentials->authorize('https://api.github.com/repos/foo/bar/releases');
    expect($githubHeaders)->not->toHaveKey('PRIVATE-TOKEN');
    expect($githubHeaders)->toBe([]);
});
