<?php

declare(strict_types=1);

use n5s\Rangelog\Util\GitHubRepoUrl;

it('is a final readonly class', function (): void {
    $reflection = new ReflectionClass(GitHubRepoUrl::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();
});

it('constructs with owner and repo strings', function (): void {
    $repo = new GitHubRepoUrl(owner: 'symfony', repo: 'console');
    expect($repo->owner)->toBe('symfony');
    expect($repo->repo)->toBe('console');
});

it('parses https://github.com/symfony/console.git via fromUrl', function (): void {
    $repo = GitHubRepoUrl::fromUrl('https://github.com/symfony/console.git');
    expect($repo)->toBeInstanceOf(GitHubRepoUrl::class);
    /** @var GitHubRepoUrl $repo */
    expect($repo->owner)->toBe('symfony');
    expect($repo->repo)->toBe('console');
});

it('parses https://github.com/symfony/console (no .git suffix) via fromUrl', function (): void {
    $repo = GitHubRepoUrl::fromUrl('https://github.com/symfony/console');
    expect($repo)->toBeInstanceOf(GitHubRepoUrl::class);
    /** @var GitHubRepoUrl $repo */
    expect($repo->owner)->toBe('symfony');
    expect($repo->repo)->toBe('console');
});

it('parses https://github.com/symfony/console/ (trailing slash) via fromUrl', function (): void {
    $repo = GitHubRepoUrl::fromUrl('https://github.com/symfony/console/');
    expect($repo)->toBeInstanceOf(GitHubRepoUrl::class);
    /** @var GitHubRepoUrl $repo */
    expect($repo->owner)->toBe('symfony');
    expect($repo->repo)->toBe('console');
});

it('parses http (not https) via fromUrl', function (): void {
    $repo = GitHubRepoUrl::fromUrl('http://github.com/foo/bar');
    expect($repo)->toBeInstanceOf(GitHubRepoUrl::class);
    /** @var GitHubRepoUrl $repo */
    expect($repo->owner)->toBe('foo');
    expect($repo->repo)->toBe('bar');
});

it('parses repo names with hyphens via fromUrl', function (): void {
    $repo = GitHubRepoUrl::fromUrl('https://github.com/vendor/some-repo.git');
    expect($repo)->toBeInstanceOf(GitHubRepoUrl::class);
    /** @var GitHubRepoUrl $repo */
    expect($repo->owner)->toBe('vendor');
    expect($repo->repo)->toBe('some-repo');
});

it('returns null for https://gitlab.com/...', function (): void {
    expect(GitHubRepoUrl::fromUrl('https://gitlab.com/group/project.git'))->toBeNull();
});

it('returns null for https://bitbucket.org/...', function (): void {
    expect(GitHubRepoUrl::fromUrl('https://bitbucket.org/team/project.git'))->toBeNull();
});

it('returns null for a non-URL string', function (): void {
    expect(GitHubRepoUrl::fromUrl('not-a-url'))->toBeNull();
});

it('returns null for an empty string', function (): void {
    expect(GitHubRepoUrl::fromUrl(''))->toBeNull();
});

it('returns null for github.com.evil.com (anchored host check)', function (): void {
    expect(GitHubRepoUrl::fromUrl('https://github.com.evil.com/foo/bar'))->toBeNull();
});

it('returns null for notgithub.com', function (): void {
    expect(GitHubRepoUrl::fromUrl('https://notgithub.com/foo/bar'))->toBeNull();
});
