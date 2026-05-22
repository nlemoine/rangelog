<?php

declare(strict_types=1);

use n5s\Rangelog\Domain\Package;
use n5s\Rangelog\Parser\ParserRegistry;
use n5s\Rangelog\Rangelog;
use n5s\Rangelog\Renderer\MarkdownRenderer;
use n5s\Rangelog\SourceProvider\GitHubReleasesResolver;
use n5s\Rangelog\SourceProvider\GitLabReleasesResolver;
use n5s\Rangelog\SourceProvider\SourceProviderChain;
use n5s\Rangelog\SourceProvider\WordPressOrgResolver;
use n5s\Rangelog\Tests\TestSupport\FixtureFetcher;

it('bare npm-style name passes Package construction without InvalidArgumentException', function (): void {
    $pkg = new Package('react', 'https://github.com/facebook/react');

    expect($pkg->name)->toBe('react');
    expect($pkg->sourceUrl)->toBe('https://github.com/facebook/react');
});

it('SourceProviderChain routes by URL host (GitHubReleasesResolver picks react, WP and GitLab fall through)', function (): void {
    $fetcher = new FixtureFetcher([]);

    $wpResolver = new WordPressOrgResolver($fetcher);
    $gitLabResolver = new GitLabReleasesResolver($fetcher);
    $gitHubResolver = new GitHubReleasesResolver($fetcher);

    $pkg = new Package('react', 'https://github.com/facebook/react');

    expect($wpResolver->supports($pkg))->toBeFalse();
    expect($gitLabResolver->supports($pkg))->toBeFalse();
    expect($gitHubResolver->supports($pkg))->toBeTrue();
});

it('bare react name flows end-to-end through Package -> chain -> resolver -> parser -> non-empty Changelog', function (): void {
    $fetcher = new FixtureFetcher([
        'https://api.github.com/repos/facebook/react/releases?per_page=100&page=1' => 'integration/three-entry-releases.json',
    ]);

    $chain = new SourceProviderChain([new GitHubReleasesResolver($fetcher)]);
    $client = new Rangelog($chain, $fetcher, ParserRegistry::defaults(), new MarkdownRenderer());
    $pkg = new Package('react', 'https://github.com/facebook/react');

    $changelog = $client->changelog($pkg, '2.0.0', '3.0.0');

    expect($changelog->entries)->not->toBeEmpty();
});
