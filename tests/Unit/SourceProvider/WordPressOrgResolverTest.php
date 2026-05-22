<?php

declare(strict_types=1);

use Http\Mock\Client as MockClient;
use n5s\Rangelog\Domain\Package;
use n5s\Rangelog\Domain\SourceTypes;
use n5s\Rangelog\Domain\VersionRange;
use n5s\Rangelog\Exception\FetchException;
use n5s\Rangelog\Exception\RateLimitedException;
use n5s\Rangelog\Exception\UnsupportedPackageException;
use n5s\Rangelog\Fetcher\HttpFetcher;
use n5s\Rangelog\SourceProvider\SourceProviderInterface;
use n5s\Rangelog\SourceProvider\WordPressOrgResolver;
use n5s\Rangelog\Tests\TestSupport\ArrayLogger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;

/**
 * Build a WordPressOrgResolver wired through a real HttpFetcher + MockClient.
 * Tests queue Response objects on $mockClient before calling resolve().
 *
 * @return array{0: MockClient, 1: WordPressOrgResolver}
 */
function buildWpResolver(?ArrayLogger $logger = null): array
{
    $factory = new Psr17Factory();
    $mockClient = new MockClient($factory);
    $fetcher = new HttpFetcher($mockClient, $factory);
    $resolver = new WordPressOrgResolver($fetcher, $logger);

    return [$mockClient, $resolver];
}

function loadAkismetTrunkReadme(): string
{
    $path = __DIR__ . '/../../Fixtures/wp/akismet-trunk-readme.txt';
    $body = file_get_contents($path);
    if ($body === false) {
        throw new LogicException("Missing fixture: {$path}");
    }

    return $body;
}

function loadSampleThemeTrunkReadme(): string
{
    $path = __DIR__ . '/../../Fixtures/wp/sample-theme-trunk-readme.txt';
    $body = file_get_contents($path);
    if ($body === false) {
        throw new LogicException("Missing fixture: {$path}");
    }

    return $body;
}

it('is a final class implementing SourceProviderInterface', function (): void {
    $reflection = new ReflectionClass(WordPressOrgResolver::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->implementsInterface(SourceProviderInterface::class))->toBeTrue();
});

it('supports() returns true for wpackagist-plugin/woocommerce (no HTTP)', function (): void {
    [$mockClient, $resolver] = buildWpResolver();
    expect($resolver->supports(new Package('wpackagist-plugin/woocommerce', 'https://wordpress.org/plugins/woocommerce/')))->toBeTrue();
    expect(count($mockClient->getRequests()))->toBe(0);
});

it('supports() returns true for wp-plugin/foo (no HTTP)', function (): void {
    [$mockClient, $resolver] = buildWpResolver();
    expect($resolver->supports(new Package('wp-plugin/foo', 'https://wordpress.org/plugins/foo/')))->toBeTrue();
    expect(count($mockClient->getRequests()))->toBe(0);
});

it('supports() returns false for symfony/console (github.com host, not wordpress.org)', function (): void {
    [$mockClient, $resolver] = buildWpResolver();
    expect($resolver->supports(new Package('symfony/console', 'https://github.com/symfony/console')))->toBeFalse();
    expect(count($mockClient->getRequests()))->toBe(0);
});

it('resolve() returns Source(GITHUB_FILE) when tags/{ver}/changelog.md returns 200 on attempt 1', function (): void {
    [$mockClient, $resolver] = buildWpResolver();
    $mockClient->addResponse(new Response(200, [], '# Changelog'));

    $source = $resolver->resolve(
        new Package('wpackagist-plugin/woocommerce', 'https://wordpress.org/plugins/woocommerce/'),
        VersionRange::changes('1.0.0', '9.0.0'),
    );

    expect($source->type)->toBe(SourceTypes::GITHUB_FILE);
    expect($source->url)->toBe('https://plugins.svn.wordpress.org/woocommerce/tags/9.0.0/changelog.md');
});

it('resolve() returns Source(GITHUB_FILE) when tags/{ver}/CHANGELOG.md returns 200 on attempt 2', function (): void {
    [$mockClient, $resolver] = buildWpResolver();
    $mockClient->addResponse(new Response(404, [], '404'));
    $mockClient->addResponse(new Response(200, [], '# Changelog'));

    $source = $resolver->resolve(
        new Package('wpackagist-plugin/woocommerce', 'https://wordpress.org/plugins/woocommerce/'),
        VersionRange::changes('1.0.0', '9.0.0'),
    );

    expect($source->type)->toBe(SourceTypes::GITHUB_FILE);
    expect($source->url)->toEndWith('tags/9.0.0/CHANGELOG.md');
});

it('resolve() returns Source(WORDPRESS_ORG) when tags/{ver}/readme.txt returns 200 on attempt 3', function (): void {
    [$mockClient, $resolver] = buildWpResolver();
    $mockClient->addResponse(new Response(404, [], '404'));
    $mockClient->addResponse(new Response(404, [], '404'));
    $mockClient->addResponse(new Response(200, [], '=== Plugin ==='));

    $source = $resolver->resolve(
        new Package('wpackagist-plugin/woocommerce', 'https://wordpress.org/plugins/woocommerce/'),
        VersionRange::changes('1.0.0', '9.0.0'),
    );

    expect($source->type)->toBe(SourceTypes::WORDPRESS_ORG);
    expect($source->url)->toEndWith('tags/9.0.0/readme.txt');
});

it('resolve() returns Source(WORDPRESS_ORG) when trunk/readme.txt returns 200 on attempt 6 (akismet truncation fixture)', function (): void {
    [$mockClient, $resolver] = buildWpResolver();
    for ($i = 0; $i < 5; ++$i) {
        $mockClient->addResponse(new Response(404, [], '404'));
    }
    $mockClient->addResponse(new Response(200, [], loadAkismetTrunkReadme()));

    $source = $resolver->resolve(
        new Package('wpackagist-plugin/akismet', 'https://wordpress.org/plugins/akismet/'),
        VersionRange::changes('4.9', '5.7'),
    );

    expect($source->type)->toBe(SourceTypes::WORDPRESS_ORG);
    expect($source->url)->toBe('https://plugins.svn.wordpress.org/akismet/trunk/readme.txt');
});

it('resolve() skips tag attempts (1-3) when VersionRange::to fails to normalize', function (): void {
    [$mockClient, $resolver] = buildWpResolver();
    $mockClient->addResponse(new Response(200, [], '# Changelog'));

    $source = $resolver->resolve(
        new Package('wpackagist-plugin/woocommerce', 'https://wordpress.org/plugins/woocommerce/'),
        VersionRange::changes('1.0', 'not-a-semver'),
    );

    expect(count($mockClient->getRequests()))->toBe(1);
    expect($source->url)->toContain('/trunk/changelog.md');
});

it('resolve() strips leading v from normalized version for SVN tag URL', function (): void {
    [$mockClient, $resolver] = buildWpResolver();
    $mockClient->addResponse(new Response(200, [], '# Changelog'));

    $source = $resolver->resolve(
        new Package('wpackagist-plugin/wordpress-seo', 'https://wordpress.org/plugins/wordpress-seo/'),
        VersionRange::changes('1.0.0', 'v27.5'),
    );

    expect($source->url)->toContain('tags/27.5/changelog.md');
});

it('resolve() throws UnsupportedPackageException when all 6 attempts return 404', function (): void {
    [$mockClient, $resolver] = buildWpResolver();
    for ($i = 0; $i < 6; ++$i) {
        $mockClient->addResponse(new Response(404, [], '404'));
    }

    $caught = null;
    try {
        $resolver->resolve(
            new Package('wpackagist-plugin/some-premium-plugin', 'https://wordpress.org/plugins/some-premium-plugin/'),
            VersionRange::changes('1.0.0', '2.0.0'),
        );
    } catch (UnsupportedPackageException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(UnsupportedPackageException::class);
    /** @var UnsupportedPackageException $caught */
    expect($caught->getMessage())->toContain('some-premium-plugin');
});

it('resolve() propagates FetchException on 5xx mid-walk', function (): void {
    [$mockClient, $resolver] = buildWpResolver();
    $mockClient->addResponse(new Response(404, [], '404'));
    $mockClient->addResponse(new Response(500, [], 'server error'));

    $caught = null;
    try {
        $resolver->resolve(
            new Package('wpackagist-plugin/woocommerce', 'https://wordpress.org/plugins/woocommerce/'),
            VersionRange::changes('1.0.0', '9.0.0'),
        );
    } catch (FetchException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(FetchException::class);
    /** @var FetchException $caught */
    expect($caught->statusCode)->toBe(500);
});

it('resolve() propagates RateLimitedException on 429', function (): void {
    [$mockClient, $resolver] = buildWpResolver();
    $mockClient->addResponse(new Response(429, ['Retry-After' => '60'], 'rate limited'));

    $resolver->resolve(
        new Package('wpackagist-plugin/woocommerce', 'https://wordpress.org/plugins/woocommerce/'),
        VersionRange::changes('1.0.0', '9.0.0'),
    );
})->throws(RateLimitedException::class);

it('emits debug per attempt + info on resolved', function (): void {
    $logger = new ArrayLogger();
    [$mockClient, $resolver] = buildWpResolver($logger);
    $mockClient->addResponse(new Response(200, [], '# Changelog'));

    $resolver->resolve(
        new Package('wpackagist-plugin/woocommerce', 'https://wordpress.org/plugins/woocommerce/'),
        VersionRange::changes('1.0.0', '9.0.0'),
    );

    $debug = array_values(array_filter(
        $logger->records,
        fn (array $r): bool => $r['level'] === 'debug' && $r['message'] === 'Trying {url}',
    ));
    expect(count($debug))->toBeGreaterThanOrEqual(1);
    expect($debug[0]['context'])->toHaveKey('url');
    expect($debug[0]['context'])->toHaveKey('attempt');
    expect($debug[0]['context'])->toHaveKey('total');
    expect($debug[0]['context']['attempt'])->toBe(1);
    expect($debug[0]['context']['total'])->toBe(6);

    $info = array_values(array_filter(
        $logger->records,
        fn (array $r): bool => $r['level'] === 'info' && $r['message'] === 'Resolved WP package {slug} from {url}',
    ));
    expect(count($info))->toBe(1);
    expect($info[0]['context']['slug'])->toBe('woocommerce');
});

it('emits warning before throwing UnsupportedPackageException on all-404', function (): void {
    $logger = new ArrayLogger();
    [$mockClient, $resolver] = buildWpResolver($logger);
    for ($i = 0; $i < 6; ++$i) {
        $mockClient->addResponse(new Response(404, [], '404'));
    }

    try {
        $resolver->resolve(
            new Package('wpackagist-plugin/some-premium-plugin', 'https://wordpress.org/plugins/some-premium-plugin/'),
            VersionRange::changes('1.0.0', '2.0.0'),
        );
    } catch (UnsupportedPackageException) {
        // expected
    }

    $warnings = array_values(array_filter(
        $logger->records,
        fn (array $r): bool => $r['level'] === 'warning' && $r['message'] === 'All 6 SVN attempts returned 404 for {slug}',
    ));
    expect(count($warnings))->toBe(1);
    expect($warnings[0]['context']['slug'])->toBe('some-premium-plugin');
});

// Theme support: WordPressOrgResolver handles wp.org themes alongside plugins via SVN host switch.

it('supports() returns true for a wp.org theme URL (no HTTP)', function (): void {
    [$mockClient, $resolver] = buildWpResolver();
    expect($resolver->supports(new Package('wpackagist-theme/twentytwentyfour', 'https://wordpress.org/themes/twentytwentyfour/')))->toBeTrue();
    expect(count($mockClient->getRequests()))->toBe(0);
});

it('supports() returns false for wordpress.org/patterns/ path', function (): void {
    [$mockClient, $resolver] = buildWpResolver();
    expect($resolver->supports(new Package('wpackagist/foo', 'https://wordpress.org/patterns/foo/')))->toBeFalse();
    expect(count($mockClient->getRequests()))->toBe(0);
});

it('supports() returns false for wordpress.org/news/ path', function (): void {
    [$mockClient, $resolver] = buildWpResolver();
    expect($resolver->supports(new Package('wpackagist/foo', 'https://wordpress.org/news/foo/')))->toBeFalse();
    expect(count($mockClient->getRequests()))->toBe(0);
});

it('resolve() returns Source(GITHUB_FILE) for theme when tags/{ver}/changelog.md returns 200 on attempt 1', function (): void {
    [$mockClient, $resolver] = buildWpResolver();
    $mockClient->addResponse(new Response(200, [], '# Changelog'));

    $source = $resolver->resolve(
        new Package('wpackagist-theme/twentytwentyfour', 'https://wordpress.org/themes/twentytwentyfour/'),
        VersionRange::changes('1.0.0', '2.0.0'),
    );

    expect($source->type)->toBe(SourceTypes::GITHUB_FILE);
    expect($source->url)->toBe('https://themes.svn.wordpress.org/twentytwentyfour/tags/2.0.0/changelog.md');
});

it('resolve() returns Source(WORDPRESS_ORG) for theme when trunk/readme.txt returns 200 on attempt 6', function (): void {
    [$mockClient, $resolver] = buildWpResolver();
    for ($i = 0; $i < 5; ++$i) {
        $mockClient->addResponse(new Response(404, [], '404'));
    }
    $mockClient->addResponse(new Response(200, [], loadSampleThemeTrunkReadme()));

    $source = $resolver->resolve(
        new Package('wpackagist-theme/twentytwentyfour', 'https://wordpress.org/themes/twentytwentyfour/'),
        VersionRange::changes('1.0.0', '2.0.0'),
    );

    expect($source->type)->toBe(SourceTypes::WORDPRESS_ORG);
    expect($source->url)->toBe('https://themes.svn.wordpress.org/twentytwentyfour/trunk/readme.txt');
});

it('resolve() walks all six theme attempts in correct order against themes.svn host', function (): void {
    [$mockClient, $resolver] = buildWpResolver();
    for ($i = 0; $i < 6; ++$i) {
        $mockClient->addResponse(new Response(404, [], '404'));
    }

    try {
        $resolver->resolve(
            new Package('wpackagist-theme/twentytwentyfour', 'https://wordpress.org/themes/twentytwentyfour/'),
            VersionRange::changes('1.0.0', '2.0.0'),
        );
    } catch (UnsupportedPackageException) {
        // expected
    }

    $requests = $mockClient->getRequests();
    expect(count($requests))->toBe(6);
    expect((string) $requests[0]->getUri())->toBe('https://themes.svn.wordpress.org/twentytwentyfour/tags/2.0.0/changelog.md');
    expect((string) $requests[1]->getUri())->toBe('https://themes.svn.wordpress.org/twentytwentyfour/tags/2.0.0/CHANGELOG.md');
    expect((string) $requests[2]->getUri())->toBe('https://themes.svn.wordpress.org/twentytwentyfour/tags/2.0.0/readme.txt');
    expect((string) $requests[3]->getUri())->toBe('https://themes.svn.wordpress.org/twentytwentyfour/trunk/changelog.md');
    expect((string) $requests[4]->getUri())->toBe('https://themes.svn.wordpress.org/twentytwentyfour/trunk/CHANGELOG.md');
    expect((string) $requests[5]->getUri())->toBe('https://themes.svn.wordpress.org/twentytwentyfour/trunk/readme.txt');
});

it('resolve() throws UnsupportedPackageException on all-404 for theme URL (applies to both plugins and themes)', function (): void {
    [$mockClient, $resolver] = buildWpResolver();
    for ($i = 0; $i < 6; ++$i) {
        $mockClient->addResponse(new Response(404, [], '404'));
    }

    $caught = null;
    try {
        $resolver->resolve(
            new Package('wpackagist-theme/twentytwentyfour', 'https://wordpress.org/themes/twentytwentyfour/'),
            VersionRange::changes('1.0.0', '2.0.0'),
        );
    } catch (UnsupportedPackageException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(UnsupportedPackageException::class);
    /** @var UnsupportedPackageException $caught */
    expect($caught->getMessage())->toContain('twentytwentyfour');
    // The exception must come AFTER the 6-attempt SVN walk, not from a
    // short-circuit slug-extract failure. Pins that themes are walked
    // through the same six-attempt mechanism as plugins.
    expect(count($mockClient->getRequests()))->toBe(6);
});

it('emits info "Resolved WP package {slug} from {url}" with theme SVN host on resolved theme', function (): void {
    $logger = new ArrayLogger();
    [$mockClient, $resolver] = buildWpResolver($logger);
    $mockClient->addResponse(new Response(200, [], '# Changelog'));

    $resolver->resolve(
        new Package('wpackagist-theme/twentytwentyfour', 'https://wordpress.org/themes/twentytwentyfour/'),
        VersionRange::changes('1.0.0', '2.0.0'),
    );

    $info = array_values(array_filter(
        $logger->records,
        fn (array $r): bool => $r['level'] === 'info' && $r['message'] === 'Resolved WP package {slug} from {url}',
    ));
    expect(count($info))->toBe(1);
    expect($info[0]['context']['slug'])->toBe('twentytwentyfour');
    expect($info[0]['context'])->toHaveKey('url');
    /** @var string $url */
    $url = $info[0]['context']['url'];
    expect($url)->toStartWith('https://themes.svn.wordpress.org/');
});
