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
use n5s\Rangelog\SourceProvider\GitHubFileResolver;
use n5s\Rangelog\SourceProvider\SourceProviderInterface;
use n5s\Rangelog\Tests\TestSupport\ArrayLogger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;

function loadGhFilePackagistFixture(string $name): string
{
    $path = __DIR__ . '/../../Fixtures/packagist/' . $name;
    $body = file_get_contents($path);
    if ($body === false) {
        throw new LogicException("Missing fixture: {$path}");
    }

    return $body;
}

/**
 * @return array{0: MockClient, 1: GitHubFileResolver}
 */
function buildGithubFileResolver(?ArrayLogger $logger = null): array
{
    $factory = new Psr17Factory();
    $mockClient = new MockClient($factory);
    $fetcher = new HttpFetcher($mockClient, $factory);
    $resolver = new GitHubFileResolver($fetcher, logger: $logger);

    return [$mockClient, $resolver];
}

it('is a final class implementing SourceProviderInterface', function (): void {
    $reflection = new ReflectionClass(GitHubFileResolver::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->implementsInterface(SourceProviderInterface::class))->toBeTrue();
});

it('supports() returns true when sourceUrl is a github.com URL (NO GitHub call made)', function (): void {
    [$mockClient, $resolver] = buildGithubFileResolver();

    expect($resolver->supports(new Package('symfony/console', 'https://github.com/symfony/console')))->toBeTrue();
    expect(count($mockClient->getRequests()))->toBe(0);
});

it('supports() returns true even for nonexistent org/repo — failure surfaces in resolve()', function (): void {
    // Under v2.0 URL-host routing, supports() only checks URL host — no HTTP probe.
    // A valid github.com URL returns true regardless of whether the repo exists.
    [$mockClient, $resolver] = buildGithubFileResolver();

    expect($resolver->supports(new Package('nonexistent/package', 'https://github.com/nonexistent/package')))->toBeTrue();
    expect(count($mockClient->getRequests()))->toBe(0);
});

it('supports() returns false when sourceUrl is non-github (gitlab)', function (): void {
    [$mockClient, $resolver] = buildGithubFileResolver();

    expect($resolver->supports(new Package('gitlab/example', 'https://gitlab.com/gitlab/example')))->toBeFalse();
    expect(count($mockClient->getRequests()))->toBe(0);
});

it('supports() returns false for 5xx on non-github URL — no HTTP call', function (): void {
    // The old supports() made HTTP calls; v2.0 does NOT. This test validates the
    // no-HTTP-call contract; a 5xx response should never be seen.
    [$mockClient, $resolver] = buildGithubFileResolver();

    expect($resolver->supports(new Package('any/package', 'https://any.example.com/package')))->toBeFalse();
    expect(count($mockClient->getRequests()))->toBe(0);
});

it('resolve() returns Source(GITHUB_FILE) when main branch CHANGELOG.md returns 200', function (): void {
    [$mockClient, $resolver] = buildGithubFileResolver();
    $mockClient->addResponse(new Response(200, [], '# Changelog'));

    $source = $resolver->resolve(
        new Package('symfony/console', 'https://github.com/symfony/console'),
        VersionRange::changes('1.0.0', '2.0.0'),
    );

    expect($source->type)->toBe(SourceTypes::GITHUB_FILE);
    expect($source->url)->toBe('https://raw.githubusercontent.com/symfony/console/main/CHANGELOG.md');
    expect($source->metadata['branch'])->toBe('main');
    expect($source->metadata['file'])->toBe('CHANGELOG.md');
});

it('resolve() falls back to master branch when main/CHANGELOG.md returns 404', function (): void {
    [$mockClient, $resolver] = buildGithubFileResolver();
    $mockClient->addResponse(new Response(404, [], '404'));
    $mockClient->addResponse(new Response(200, [], '# Changelog'));

    $source = $resolver->resolve(
        new Package('symfony/console', 'https://github.com/symfony/console'),
        VersionRange::changes('1.0.0', '2.0.0'),
    );

    expect($source->metadata['branch'])->toBe('master');
    expect($source->metadata['file'])->toBe('CHANGELOG.md');
    expect($source->url)->toContain('/master/CHANGELOG.md');
});

it('resolve() walks file priority CHANGELOG.md → CHANGELOG → HISTORY.md → CHANGES.md', function (): void {
    [$mockClient, $resolver] = buildGithubFileResolver();
    // CHANGELOG.md probe: main 404, master 404, 2.0 404, 2.x 404, 2.0.x 404
    // (range->to='2.0.0' derives ['2.0', '2.x', '2.0.x'] — version-branch walking)
    $mockClient->addResponse(new Response(404, [], '404')); // main/CHANGELOG.md
    $mockClient->addResponse(new Response(404, [], '404')); // master/CHANGELOG.md
    $mockClient->addResponse(new Response(404, [], '404')); // 2.0/CHANGELOG.md
    $mockClient->addResponse(new Response(404, [], '404')); // 2.x/CHANGELOG.md
    $mockClient->addResponse(new Response(404, [], '404')); // 2.0.x/CHANGELOG.md
    // Branch fallback to 'main' after first-file all-404. Subsequent files probe main only.
    // CHANGELOG on main: 404
    $mockClient->addResponse(new Response(404, [], '404'));
    // HISTORY.md on main: 200
    $mockClient->addResponse(new Response(200, [], '# History'));

    $source = $resolver->resolve(
        new Package('symfony/console', 'https://github.com/symfony/console'),
        VersionRange::changes('1.0.0', '2.0.0'),
    );

    expect($source->metadata['file'])->toBe('HISTORY.md');
    expect($source->metadata['branch'])->toBe('main');
});

it('resolve() throws ChangelogNotFoundException when all 4 files × probed branches return 404', function (): void {
    [$mockClient, $resolver] = buildGithubFileResolver();
    // range->to='2.0.0' derives ['2.0', '2.x', '2.0.x'] — 5 branches for CHANGELOG.md,
    // then 'main' only for CHANGELOG, HISTORY.md, CHANGES.md = 5 + 3 = 8 total 404s.
    // Queue 10 to cover the full walk with safety margin.
    for ($i = 0; $i < 10; ++$i) {
        $mockClient->addResponse(new Response(404, [], '404'));
    }

    $caught = null;
    try {
        $resolver->resolve(
            new Package('symfony/console', 'https://github.com/symfony/console'),
            VersionRange::changes('1.0.0', '2.0.0'),
        );
    } catch (ChangelogNotFoundException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ChangelogNotFoundException::class);
    /** @var ChangelogNotFoundException $caught */
    expect($caught->getMessage())->toContain('symfony/console');
});

it('resolve() propagates FetchException on 5xx mid-walk', function (): void {
    [$mockClient, $resolver] = buildGithubFileResolver();
    $mockClient->addResponse(new Response(500, [], 'server error'));

    $resolver->resolve(
        new Package('symfony/console', 'https://github.com/symfony/console'),
        VersionRange::changes('1.0.0', '2.0.0'),
    );
})->throws(FetchException::class);

it('resolve() propagates RateLimitedException on 429', function (): void {
    [$mockClient, $resolver] = buildGithubFileResolver();
    $mockClient->addResponse(new Response(429, ['Retry-After' => '60'], 'rate limited'));

    $resolver->resolve(
        new Package('symfony/console', 'https://github.com/symfony/console'),
        VersionRange::changes('1.0.0', '2.0.0'),
    );
})->throws(RateLimitedException::class);

it('emits debug per file attempt, debug on branch confirmation, info on resolved', function (): void {
    $logger = new ArrayLogger();
    [$mockClient, $resolver] = buildGithubFileResolver($logger);
    $mockClient->addResponse(new Response(200, [], '# Changelog'));

    $resolver->resolve(
        new Package('symfony/console', 'https://github.com/symfony/console'),
        VersionRange::changes('1.0.0', '2.0.0'),
    );

    $tryingDebug = array_values(array_filter(
        $logger->records,
        fn (array $r): bool => $r['level'] === 'debug' && $r['message'] === 'Trying {url}',
    ));
    expect(count($tryingDebug))->toBeGreaterThanOrEqual(1);

    $branchDebug = array_values(array_filter(
        $logger->records,
        fn (array $r): bool => $r['level'] === 'debug' && $r['message'] === 'Branch {branch} confirmed for {repo}',
    ));
    expect(count($branchDebug))->toBe(1);
    expect($branchDebug[0]['context']['branch'])->toBe('main');

    $info = array_values(array_filter(
        $logger->records,
        fn (array $r): bool => $r['level'] === 'info' && $r['message'] === 'Resolved {package} from {url}',
    ));
    expect(count($info))->toBe(1);
    expect($info[0]['context']['branch'])->toBe('main');
    expect($info[0]['context']['file'])->toBe('CHANGELOG.md');
});

it('resolve() finds CHANGELOG.md on main without probing version branches', function (): void {
    [$mockClient, $resolver] = buildGithubFileResolver();
    // (1) main/CHANGELOG.md → 200 immediately — version branches MUST NOT be probed
    $mockClient->addResponse(new Response(200, [], '# Changelog'));

    $source = $resolver->resolve(
        new Package('symfony/console', 'https://github.com/symfony/console'),
        VersionRange::changes('1.0.0', '2.0.0'),
    );

    expect($source->metadata['branch'])->toBe('main');
    // Only 1 request: main (version branches skipped because main hit first); no Packagist.
    expect(count($mockClient->getRequests()))->toBe(1);
});

it('resolve() throws ChangelogNotFoundException when ALL files × ALL branches return 404', function (): void {
    [$mockClient, $resolver] = buildGithubFileResolver();
    // Queue 21 404s to cover 4 files × 5 branches + safety margin
    for ($i = 0; $i < 21; ++$i) {
        $mockClient->addResponse(new Response(404, [], '404'));
    }

    $caught = null;
    try {
        $resolver->resolve(
            new Package('symfony/console', 'https://github.com/symfony/console'),
            VersionRange::changes('7.4.0', '7.4.1'),
        );
    } catch (ChangelogNotFoundException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ChangelogNotFoundException::class);
    /** @var ChangelogNotFoundException $caught */
    expect($caught->getMessage())->toContain('symfony/console');
});
