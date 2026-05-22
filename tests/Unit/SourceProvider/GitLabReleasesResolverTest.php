<?php

declare(strict_types=1);

use Http\Mock\Client as MockClient;
use n5s\Rangelog\Domain\Package;
use n5s\Rangelog\Domain\SourceTypes;
use n5s\Rangelog\Domain\VersionRange;
use n5s\Rangelog\Exception\FetchException;
use n5s\Rangelog\Exception\RateLimitedException;
use n5s\Rangelog\Fetcher\HttpFetcher;
use n5s\Rangelog\SourceProvider\GitLabReleasesResolver;
use n5s\Rangelog\SourceProvider\SourceProviderInterface;
use n5s\Rangelog\Tests\TestSupport\ArrayLogger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use ReflectionClass;

function loadGitlabReleasesFixture(string $name): string
{
    $path = __DIR__ . '/../../Fixtures/gitlab/releases/' . $name;
    $body = file_get_contents($path);
    if ($body === false) {
        throw new LogicException("Missing fixture: {$path}");
    }

    return $body;
}

/**
 * Build a GitLabReleasesResolver wired through a real HttpFetcher sharing a
 * single MockClient. Tests queue responses on the mock for GitLab Releases.
 *
 * @return array{0: MockClient, 1: GitLabReleasesResolver}
 */
function buildGitlabReleasesResolver(?ArrayLogger $logger = null, string $host = 'gitlab.com'): array
{
    $factory = new Psr17Factory();
    $mockClient = new MockClient($factory);
    $fetcher = new HttpFetcher($mockClient, $factory);
    $resolver = new GitLabReleasesResolver($fetcher, host: $host, logger: $logger);

    return [$mockClient, $resolver];
}

it('is a final class implementing SourceProviderInterface', function (): void {
    $reflection = new ReflectionClass(GitLabReleasesResolver::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->implementsInterface(SourceProviderInterface::class))->toBeTrue();
});

it('supports() returns true for https://gitlab.com/group/project (defense-in-depth host+regex check)', function (): void {
    [$mockClient, $resolver] = buildGitlabReleasesResolver();

    expect($resolver->supports(new Package('any', 'https://gitlab.com/gitlab-org/release-cli')))->toBeTrue();
    expect(count($mockClient->getRequests()))->toBe(0);
});

it('supports() returns false for github.com URLs (cross-provider isolation)', function (): void {
    [$mockClient, $resolver] = buildGitlabReleasesResolver();

    expect($resolver->supports(new Package('any', 'https://github.com/symfony/console')))->toBeFalse();
    expect(count($mockClient->getRequests()))->toBe(0);
});

it('supports() returns false for gitlab.com.evil.com (subdomain spoof rejection)', function (): void {
    [$mockClient, $resolver] = buildGitlabReleasesResolver();

    expect($resolver->supports(new Package('any', 'https://gitlab.com.evil.com/group/project')))->toBeFalse();
    expect(count($mockClient->getRequests()))->toBe(0);
});

it('routes to custom host gitlab.example.com when constructor host param is supplied', function (): void {
    [$mockClient, $resolver] = buildGitlabReleasesResolver(host: 'gitlab.example.com');
    $mockClient->addResponse(new Response(200, [], loadGitlabReleasesFixture('release-cli-empty.json')));

    $source = $resolver->resolve(
        new Package('any', 'https://gitlab.example.com/group/project'),
        VersionRange::changes('1.0.0', '2.0.0'),
    );

    $requests = $mockClient->getRequests();
    expect(count($requests))->toBeGreaterThanOrEqual(1);
    expect($requests[0]->getUri()->getHost())->toBe('gitlab.example.com');
    expect($source->url)->toStartWith('https://gitlab.example.com/api/v4/projects/group%2Fproject/releases?per_page=100&page=1&order_by=released_at&sort=desc');
});

it('builds the page-1 URL with rawurlencoded project path AND explicit order_by=released_at&sort=desc query params', function (): void {
    [$mockClient, $resolver] = buildGitlabReleasesResolver();
    $mockClient->addResponse(new Response(200, [], loadGitlabReleasesFixture('release-cli-empty.json')));

    $resolver->resolve(
        new Package('any', 'https://gitlab.com/gitlab-org/security/cves'),
        VersionRange::changes('1.0.0', '2.0.0'),
    );

    $requests = $mockClient->getRequests();
    expect(count($requests))->toBeGreaterThanOrEqual(1);
    $uri = $requests[0]->getUri();
    expect($uri->getHost())->toBe('gitlab.com');
    expect($uri->getPath())->toBe('/api/v4/projects/gitlab-org%2Fsecurity%2Fcves/releases');
    parse_str($uri->getQuery(), $query);
    expect($query['per_page'])->toBe('100');
    expect($query['page'])->toBe('1');
    expect($query['order_by'])->toBe('released_at');
    expect($query['sort'])->toBe('desc');
});

it('resolve() paginates pages 1..N stopping at first empty/short page; sets prefetchedBody to concatenated JSON array', function (): void {
    [$mockClient, $resolver] = buildGitlabReleasesResolver();
    // page 1 = 100 entries (forces loop into page 2), page 2 = 3 entries,
    // page 3 = empty array (triggers loop break — consistent with GitHubReleasesResolver pattern)
    $mockClient->addResponse(new Response(200, [], loadGitlabReleasesFixture('release-cli-page1-multi.json')));
    $mockClient->addResponse(new Response(200, [], loadGitlabReleasesFixture('release-cli-page2.json')));
    $mockClient->addResponse(new Response(200, [], loadGitlabReleasesFixture('release-cli-empty.json')));

    $source = $resolver->resolve(
        new Package('any', 'https://gitlab.com/gitlab-org/release-cli'),
        VersionRange::changes('0.0.0', '2.0.0'),
    );

    expect($source->type)->toBe(SourceTypes::GITLAB_RELEASES);
    expect($source->prefetchedBody)->toBeString();
    /** @var string $prefetched */
    $prefetched = $source->prefetchedBody;
    $decoded = json_decode($prefetched, true, 8, JSON_THROW_ON_ERROR);
    expect(is_array($decoded))->toBeTrue();
    /** @var array<int, mixed> $decoded */
    expect(count($decoded))->toBe(103); // 100 + 3

    expect($source->metadata['pages_fetched'])->toBe(2);
    expect($source->metadata['releases_count'])->toBe(103);
    expect($source->metadata['truncation_signal'])->toBeNull();
});

it('resolve() breaks on first empty array even if PAGINATION_CAP not reached', function (): void {
    [$mockClient, $resolver] = buildGitlabReleasesResolver();
    $mockClient->addResponse(new Response(200, [], loadGitlabReleasesFixture('release-cli-empty.json')));

    $source = $resolver->resolve(
        new Package('any', 'https://gitlab.com/gitlab-org/release-cli'),
        VersionRange::changes('1.0.0', '2.0.0'),
    );

    expect($source->metadata['pages_fetched'])->toBe(0);
    expect($source->metadata['releases_count'])->toBe(0);
    expect($source->prefetchedBody)->toBe('[]');
});

it('resolve() sets truncation_signal=gitlab_releases_capped when 10 pages × 100 entries = 1000 cap is hit', function (): void {
    [$mockClient, $resolver] = buildGitlabReleasesResolver();
    // Queue cap-hit-page10.json 10 times to hit the 1000-entry cap
    for ($i = 0; $i < 10; ++$i) {
        $mockClient->addResponse(new Response(200, [], loadGitlabReleasesFixture('cap-hit-page10.json')));
    }

    // Use from=0.0.0 so none of the v9.x entries trigger early-exit
    $source = $resolver->resolve(
        new Package('any', 'https://gitlab.com/gitlab-org/release-cli'),
        VersionRange::changes('0.0.0', '10.0.0'),
    );

    expect($source->metadata['truncation_signal'])->toBe('gitlab_releases_capped');
    expect($source->metadata['pages_fetched'])->toBe(10);
    expect($source->metadata['releases_count'])->toBe(1000);
});

it('resolve() propagates FetchException on 5xx response mid-pagination', function (): void {
    [$mockClient, $resolver] = buildGitlabReleasesResolver();
    // Use cap-hit-page10.json (v9.x entries) so no entry is <= from=0.0.0;
    // the boundary scan never triggers, and the loop continues to page 2 (500).
    $mockClient->addResponse(new Response(200, [], loadGitlabReleasesFixture('cap-hit-page10.json')));
    $mockClient->addResponse(new Response(500, [], 'server error'));

    $resolver->resolve(
        new Package('any', 'https://gitlab.com/gitlab-org/release-cli'),
        VersionRange::changes('0.0.0', '10.0.0'),
    );
})->throws(FetchException::class);

it('resolve() propagates RateLimitedException on 429 response', function (): void {
    [$mockClient, $resolver] = buildGitlabReleasesResolver();
    $mockClient->addResponse(new Response(
        429,
        ['Retry-After' => '60'],
        'rate limited',
    ));

    $resolver->resolve(
        new Package('any', 'https://gitlab.com/gitlab-org/release-cli'),
        VersionRange::changes('1.0.0', '9.0.0'),
    );
})->throws(RateLimitedException::class);

it('resolve() early-exit reads tag_name STRICTLY: a non-semver tag_name silently skips AND the resolver does NOT fall through to the name field for boundary checking — verified by asserting the "Releases pagination boundary reached" debug log is ABSENT', function (): void {
    $logger = new ArrayLogger();
    [$mockClient, $resolver] = buildGitlabReleasesResolver($logger);

    // Queue TWO responses: page 1 = strict-boundary-tag.json (1 entry),
    // page 2 = empty (triggers loop break). count(1) > 0 so the loop continues
    // to page 2 — the loop only breaks on count == 0.
    // Entry on page 1: tag_name='rolling' (non-semver), name='3.10.0' (semver)
    $mockClient->addResponse(new Response(200, [], loadGitlabReleasesFixture('strict-boundary-tag.json')));
    $mockClient->addResponse(new Response(200, [], loadGitlabReleasesFixture('release-cli-empty.json')));

    // VersionRange: from='3.11.0', to='4.0.0'
    // Under STRICT (correct), tag_name='rolling' fails normalize() and the boundary scan silently
    // skips this entry. Under LENIENT (buggy — `tag_name ?? name` fallback),
    // tag_name would fall through to name='3.10.0', and Comparator::lessThanOrEqualTo('3.10.0', '3.11.0')
    // would return TRUE, triggering the boundary log 'Releases pagination boundary reached at
    // {project}: from=3.11.0, stopping after page 1'. The negative assertion on the boundary log
    // catches the lenient regression — this is the load-bearing assertion of this test.
    // pages_fetched=1 and releases_count=1 are observed under BOTH strict and lenient because only
    // page 1 has entries; page 2 is empty (loop break). Only the lenient form emits the boundary-reached log.
    $source = $resolver->resolve(
        new Package('any', 'https://gitlab.com/gitlab-org/release-cli'),
        VersionRange::changes('3.11.0', '4.0.0'),
    );

    expect($source->metadata['pages_fetched'])->toBe(1);
    expect($source->metadata['releases_count'])->toBe(1);

    // LOAD-BEARING ASSERTION: the boundary-reached log MUST be absent under the strict policy
    expect(array_filter(
        $logger->records,
        fn (array $r): bool => str_starts_with($r['message'], 'Releases pagination boundary reached'),
    ))->toBeEmpty();
});

it('emits PSR-3 events mirroring GitHubReleasesResolver wording with GitLab + {project} substitutions', function (): void {
    $logger = new ArrayLogger();
    [$mockClient, $resolver] = buildGitlabReleasesResolver($logger);
    $mockClient->addResponse(new Response(200, [], loadGitlabReleasesFixture('release-cli-page1-multi.json')));
    $mockClient->addResponse(new Response(200, [], loadGitlabReleasesFixture('release-cli-page2.json')));
    $mockClient->addResponse(new Response(200, [], loadGitlabReleasesFixture('release-cli-empty.json')));

    $resolver->resolve(
        new Package('any', 'https://gitlab.com/gitlab-org/release-cli'),
        VersionRange::changes('0.0.0', '2.0.0'),
    );

    // At least one debug record with correct per-page message and 'project' context key
    $debugPage = array_values(array_filter(
        $logger->records,
        fn (array $r): bool => $r['level'] === 'debug' && str_contains($r['message'], 'Fetching releases page'),
    ));
    expect(count($debugPage))->toBeGreaterThanOrEqual(1);
    expect($debugPage[0]['context'])->toHaveKey('project');
    expect($debugPage[0]['context']['project'])->toBe('gitlab-org/release-cli');

    // At least one info record with completion message
    $infoComplete = array_values(array_filter(
        $logger->records,
        fn (array $r): bool => $r['level'] === 'info' && $r['message'] === 'Fetched {count} releases from {project} across {pages} pages',
    ));
    expect(count($infoComplete))->toBe(1);

    // NO log record context contains 'body', 'description', or 'raw' keys (body-leak guard)
    foreach ($logger->records as $record) {
        expect($record['context'])->not->toHaveKey('body');
        expect($record['context'])->not->toHaveKey('description');
        expect($record['context'])->not->toHaveKey('raw');
    }
});
