<?php

declare(strict_types=1);

use Http\Mock\Client as MockClient;
use n5s\Rangelog\Domain\Package;
use n5s\Rangelog\Domain\SourceTypes;
use n5s\Rangelog\Domain\VersionRange;
use n5s\Rangelog\Exception\ChangelogNotFoundException;
use n5s\Rangelog\Exception\FetchException;
use n5s\Rangelog\Exception\RateLimitedException;
use n5s\Rangelog\Fetcher\HttpFetcher;
use n5s\Rangelog\SourceProvider\GitLabFileResolver;
use n5s\Rangelog\SourceProvider\SourceProviderInterface;
use n5s\Rangelog\Tests\TestSupport\ArrayLogger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use ReflectionClass;

/**
 * @return array{0: MockClient, 1: GitLabFileResolver}
 */
function buildGitlabFileResolver(?ArrayLogger $logger = null, string $host = 'gitlab.com'): array
{
    $factory = new Psr17Factory();
    $mockClient = new MockClient($factory);
    $fetcher = new HttpFetcher($mockClient, $factory);
    $resolver = new GitLabFileResolver($fetcher, host: $host, logger: $logger);

    return [$mockClient, $resolver];
}

it('is a final class implementing SourceProviderInterface', function (): void {
    $reflection = new ReflectionClass(GitLabFileResolver::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->implementsInterface(SourceProviderInterface::class))->toBeTrue();
});

it('supports() returns true for gitlab.com URLs (defense-in-depth host + GitLabProjectPath regex)', function (): void {
    [$mockClient, $resolver] = buildGitlabFileResolver();

    expect($resolver->supports(new Package('any', 'https://gitlab.com/gitlab-org/release-cli')))->toBeTrue();
    expect(count($mockClient->getRequests()))->toBe(0);
});

it('supports() returns false for github.com URLs (cross-provider isolation)', function (): void {
    [$mockClient, $resolver] = buildGitlabFileResolver();

    expect($resolver->supports(new Package('any', 'https://github.com/symfony/console')))->toBeFalse();
    expect(count($mockClient->getRequests()))->toBe(0);
});

it('supports() returns false for gitlab.com.evil.com (subdomain spoof rejection)', function (): void {
    [$mockClient, $resolver] = buildGitlabFileResolver();

    expect($resolver->supports(new Package('any', 'https://gitlab.com.evil.com/foo/bar')))->toBeFalse();
    expect(count($mockClient->getRequests()))->toBe(0);
});

it('resolve() returns Source(GITLAB_FILE) when main branch CHANGELOG.md returns 200; URL uses the Repository Files raw endpoint', function (): void {
    [$mockClient, $resolver] = buildGitlabFileResolver();

    $changelogBody = (string) file_get_contents(
        __DIR__ . '/../../Fixtures/gitlab/files/release-cli-CHANGELOG.md',
    );
    $mockClient->addResponse(new Response(200, [], $changelogBody));

    $source = $resolver->resolve(
        new Package('any', 'https://gitlab.com/gitlab-org/release-cli'),
        VersionRange::changes('0.15.0', '0.16.0'),
    );

    expect($source->type)->toBe(SourceTypes::GITLAB_FILE);
    expect($source->url)->toBe('https://gitlab.com/api/v4/projects/gitlab-org%2Frelease-cli/repository/files/CHANGELOG.md/raw?ref=main');
    expect($source->metadata['branch'])->toBe('main');
    expect($source->metadata['file'])->toBe('CHANGELOG.md');
});

it('resolve() walks file priority CHANGELOG.md → CHANGELOG → HISTORY.md → CHANGES.md', function (): void {
    [$mockClient, $resolver] = buildGitlabFileResolver();

    // range->to='2.0.0' derives ['2.0', '2.x', '2.0.x'] — 5 branches for CHANGELOG.md
    $mockClient->addResponse(new Response(404, [], '{"message":"404 File Not Found"}')); // main/CHANGELOG.md
    $mockClient->addResponse(new Response(404, [], '{"message":"404 File Not Found"}')); // master/CHANGELOG.md
    $mockClient->addResponse(new Response(404, [], '{"message":"404 File Not Found"}')); // 2.0/CHANGELOG.md
    $mockClient->addResponse(new Response(404, [], '{"message":"404 File Not Found"}')); // 2.x/CHANGELOG.md
    $mockClient->addResponse(new Response(404, [], '{"message":"404 File Not Found"}')); // 2.0.x/CHANGELOG.md
    // Branch fallback to 'main' after first-file all-404. Subsequent files probe main only.
    $mockClient->addResponse(new Response(404, [], '{"message":"404 File Not Found"}')); // CHANGELOG@main
    $mockClient->addResponse(new Response(200, [], '# History'));                         // HISTORY.md@main

    $source = $resolver->resolve(
        new Package('any', 'https://gitlab.com/gitlab-org/release-cli'),
        VersionRange::changes('1.0.0', '2.0.0'),
    );

    expect($source->metadata['file'])->toBe('HISTORY.md');
    expect($source->metadata['branch'])->toBe('main');
    expect($source->url)->toContain('HISTORY.md/raw?ref=main');
});

it('resolve() locks the branch after the first file\'s first 200; subsequent files probe the locked branch only', function (): void {
    [$mockClient, $resolver] = buildGitlabFileResolver();

    // Queue: main/CHANGELOG.md → 404, master/CHANGELOG.md → 200
    $mockClient->addResponse(new Response(404, [], '{"message":"404 File Not Found"}')); // main/CHANGELOG.md
    $mockClient->addResponse(new Response(200, [], '# Changelog'));                       // master/CHANGELOG.md

    $source = $resolver->resolve(
        new Package('any', 'https://gitlab.com/gitlab-org/release-cli'),
        VersionRange::changes('0.15.0', '0.16.0'),
    );

    expect($source->metadata['branch'])->toBe('master');
    expect($source->metadata['file'])->toBe('CHANGELOG.md');
    // Only 2 requests: main (404) + master (200); no further probes needed
    expect(count($mockClient->getRequests()))->toBe(2);
});

it('resolve() walks version-derived branch {major}.{minor} when range->to=7.4.1 produces ["7.4","7.x","7.4.x"] (via VersionBranchDeriver)', function (): void {
    [$mockClient, $resolver] = buildGitlabFileResolver();

    // Queue: main/CHANGELOG.md → 404, master/CHANGELOG.md → 404, 7.4/CHANGELOG.md → 200
    $mockClient->addResponse(new Response(404, [], '{"message":"404 File Not Found"}')); // main/CHANGELOG.md
    $mockClient->addResponse(new Response(404, [], '{"message":"404 File Not Found"}')); // master/CHANGELOG.md
    $mockClient->addResponse(new Response(200, [], '# Changelog 7.4'));                   // 7.4/CHANGELOG.md

    $source = $resolver->resolve(
        new Package('any', 'https://gitlab.com/example/project'),
        VersionRange::changes('7.4.0', '7.4.1'),
    );

    expect($source->metadata['branch'])->toBe('7.4');
    expect($source->url)->toBe('https://gitlab.com/api/v4/projects/example%2Fproject/repository/files/CHANGELOG.md/raw?ref=7.4');
});

it('encodes nested-group namespace path as %2F-separated segments in the API URL', function (): void {
    [$mockClient, $resolver] = buildGitlabFileResolver();

    $mockClient->addResponse(new Response(200, [], '# CVEs Changelog'));

    $source = $resolver->resolve(
        new Package('any', 'https://gitlab.com/gitlab-org/security/cves'),
        VersionRange::changes('1.0.0', '2.0.0'),
    );

    expect($source->url)->toContain('/api/v4/projects/gitlab-org%2Fsecurity%2Fcves/repository/files/CHANGELOG.md/raw');
});

it('resolve() throws ChangelogNotFoundException when ALL 4 files × ALL branches return 404', function (): void {
    [$mockClient, $resolver] = buildGitlabFileResolver();

    // Queue 20 × 404 to cover 4 files × 5 branches (2 initial + 3 derived for 2.0.0)
    for ($i = 0; $i < 20; ++$i) {
        $mockClient->addResponse(new Response(404, [], '{"message":"404 File Not Found"}'));
    }

    $caught = null;
    try {
        $resolver->resolve(
            new Package('any', 'https://gitlab.com/gitlab-org/release-cli'),
            VersionRange::changes('1.0.0', '2.0.0'),
        );
    } catch (ChangelogNotFoundException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ChangelogNotFoundException::class);
    /** @var ChangelogNotFoundException $caught */
    expect($caught->getMessage())->toContain('gitlab-org/release-cli');
});

it('resolve() propagates FetchException on 5xx response', function (): void {
    [$mockClient, $resolver] = buildGitlabFileResolver();
    $mockClient->addResponse(new Response(500, [], 'Internal Server Error'));

    $resolver->resolve(
        new Package('any', 'https://gitlab.com/gitlab-org/release-cli'),
        VersionRange::changes('0.15.0', '0.16.0'),
    );
})->throws(FetchException::class);

it('resolve() propagates RateLimitedException on 429 response', function (): void {
    [$mockClient, $resolver] = buildGitlabFileResolver();
    $mockClient->addResponse(new Response(429, ['Retry-After' => '60'], 'rate limited'));

    $resolver->resolve(
        new Package('any', 'https://gitlab.com/gitlab-org/release-cli'),
        VersionRange::changes('0.15.0', '0.16.0'),
    );
})->throws(RateLimitedException::class);

it('routes to custom host gitlab.example.com when constructor host param is supplied', function (): void {
    [$mockClient, $resolver] = buildGitlabFileResolver(host: 'gitlab.example.com');

    $mockClient->addResponse(new Response(200, [], '# Changelog'));

    $source = $resolver->resolve(
        new Package('any', 'https://gitlab.example.com/foo/bar'),
        VersionRange::changes('1.0.0', '2.0.0'),
    );

    $requests = $mockClient->getRequests();
    expect(count($requests))->toBeGreaterThanOrEqual(1);
    $uri = $requests[0]->getUri();
    expect($uri->getHost())->toBe('gitlab.example.com');
    expect($uri->getPath())->toStartWith('/api/v4/projects/foo%2Fbar/repository/files/CHANGELOG.md/raw');
    expect($source->metadata['branch'])->toBe('main');
});

it('rawurlencodes the filename in the URL path (currently a no-op for the 4 hardcoded filenames but harmless)', function (): void {
    [$mockClient, $resolver] = buildGitlabFileResolver();

    $mockClient->addResponse(new Response(200, [], '# Changelog'));

    $source = $resolver->resolve(
        new Package('any', 'https://gitlab.com/gitlab-org/release-cli'),
        VersionRange::changes('0.15.0', '0.16.0'),
    );

    // CHANGELOG.md contains only alphanumeric + dot — rawurlencode is a no-op; path is literal
    expect($source->url)->toContain('/repository/files/CHANGELOG.md/raw');
    expect($source->metadata['file'])->toBe('CHANGELOG.md');
});
