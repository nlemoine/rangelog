<?php

declare(strict_types=1);

use Http\Mock\Client as MockClient;
use n5s\Rangelog\Auth\CredentialProviderInterface;
use n5s\Rangelog\Cache\Psr6CacheAdapter;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Fetcher\AuthorizingFetcher;
use n5s\Rangelog\Fetcher\CachingFetcher;
use n5s\Rangelog\Fetcher\FetcherInterface;
use n5s\Rangelog\Fetcher\FetcherStack;
use n5s\Rangelog\Fetcher\HttpFetcher;
use n5s\Rangelog\Tests\TestSupport\ArrayCachePool;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;

it('per-host credentials never bleed across hosts', function (): void {
    $factory = new Psr17Factory();
    $mockClient = new MockClient($factory);

    $mockClient->addResponse(new Response(200, ['Content-Type' => 'application/json'], '{"github":true}'));
    $mockClient->addResponse(new Response(200, ['Content-Type' => 'application/json'], '{"gitlab":true}'));

    $credentials = new class () implements CredentialProviderInterface {
        public function authorize(string $url): array
        {
            $host = parse_url($url, PHP_URL_HOST) ?? '';

            return match ($host) {
                'api.github.com' => ['Authorization' => 'Bearer gh-token'],
                'gitlab.com' => ['PRIVATE-TOKEN' => 'gl-token'],
                default => [],
            };
        }
    };

    $stack = new FetcherStack(
        base: new HttpFetcher($mockClient, $factory),
        decorators: [
            fn (FetcherInterface $i): AuthorizingFetcher => new AuthorizingFetcher($i, $credentials),
            fn (FetcherInterface $i): CachingFetcher => new CachingFetcher($i, new Psr6CacheAdapter(new ArrayCachePool()), defaultTtl: 3600),
        ],
    );

    $stack->fetch(new Source(type: 'github_releases', url: 'https://api.github.com/repos/example/example/releases'));
    $stack->fetch(new Source(type: 'gitlab_releases', url: 'https://gitlab.com/api/v4/projects/foo%2Fbar/releases'));

    $requests = $mockClient->getRequests();
    expect($requests)->toHaveCount(2);

    // First captured request -> github
    expect($requests[0]->getUri()->getHost())->toBe('api.github.com');
    expect($requests[0]->getHeaderLine('Authorization'))->toBe('Bearer gh-token');
    expect($requests[0]->getHeaderLine('PRIVATE-TOKEN'))->toBe('');

    // Second captured request -> gitlab
    expect($requests[1]->getUri()->getHost())->toBe('gitlab.com');
    expect($requests[1]->getHeaderLine('PRIVATE-TOKEN'))->toBe('gl-token');
    expect($requests[1]->getHeaderLine('Authorization'))->toBe('');
});
