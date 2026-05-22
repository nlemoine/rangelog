<?php

declare(strict_types=1);

use n5s\Rangelog\Domain\SourceTypes;
use n5s\Rangelog\Util\GitLabProjectPath;

it('is a final readonly class', function (): void {
    $reflection = new ReflectionClass(GitLabProjectPath::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();
});

it('parses two-segment URL into namespace + project + encodedPath', function (): void {
    $result = GitLabProjectPath::fromUrl('https://gitlab.com/gitlab-org/release-cli');
    expect($result)->toBeInstanceOf(GitLabProjectPath::class);
    /** @var GitLabProjectPath $result */
    expect($result->namespace)->toBe('gitlab-org')
        ->and($result->project)->toBe('release-cli')
        ->and($result->encodedPath)->toBe('gitlab-org%2Frelease-cli');
});

it('parses nested-group URL with two-deep namespace', function (): void {
    $result = GitLabProjectPath::fromUrl('https://gitlab.com/gitlab-org/security/cves');
    expect($result)->toBeInstanceOf(GitLabProjectPath::class);
    /** @var GitLabProjectPath $result */
    expect($result->namespace)->toBe('gitlab-org/security')
        ->and($result->project)->toBe('cves')
        ->and($result->encodedPath)->toBe('gitlab-org%2Fsecurity%2Fcves');
});

it('parses arbitrary-depth nested groups (6-level nesting)', function (): void {
    $result = GitLabProjectPath::fromUrl('https://gitlab.com/a/b/c/d/e/f/project');
    expect($result)->toBeInstanceOf(GitLabProjectPath::class);
    /** @var GitLabProjectPath $result */
    expect($result->namespace)->toBe('a/b/c/d/e/f')
        ->and($result->project)->toBe('project')
        ->and($result->encodedPath)->toBe('a%2Fb%2Fc%2Fd%2Fe%2Ff%2Fproject');
});

it('strips .git suffix', function (): void {
    $result = GitLabProjectPath::fromUrl('https://gitlab.com/gitlab-org/release-cli.git');
    expect($result)->toBeInstanceOf(GitLabProjectPath::class);
    /** @var GitLabProjectPath $result */
    expect($result->namespace)->toBe('gitlab-org')
        ->and($result->project)->toBe('release-cli')
        ->and($result->encodedPath)->toBe('gitlab-org%2Frelease-cli');
});

it('accepts trailing slash', function (): void {
    $result = GitLabProjectPath::fromUrl('https://gitlab.com/gitlab-org/release-cli/');
    expect($result)->toBeInstanceOf(GitLabProjectPath::class);
    /** @var GitLabProjectPath $result */
    expect($result->namespace)->toBe('gitlab-org')
        ->and($result->project)->toBe('release-cli')
        ->and($result->encodedPath)->toBe('gitlab-org%2Frelease-cli');
});

it('is case-insensitive on URL scheme and host (matches GitHubRepoUrl precedent)', function (): void {
    $result = GitLabProjectPath::fromUrl('HTTPS://GITLAB.COM/Group/Project');
    expect($result)->toBeInstanceOf(GitLabProjectPath::class);
    /** @var GitLabProjectPath $result */
    expect($result->namespace)->toBe('Group')
        ->and($result->project)->toBe('Project')
        ->and($result->encodedPath)->toBe('Group%2FProject');
});

it('accepts http:// in addition to https://', function (): void {
    $result = GitLabProjectPath::fromUrl('http://gitlab.com/foo/bar');
    expect($result)->toBeInstanceOf(GitLabProjectPath::class);
    /** @var GitLabProjectPath $result */
    expect($result->namespace)->toBe('foo')
        ->and($result->project)->toBe('bar');
});

it('rejects subdomain spoofing — gitlab.com.evil.com', function (): void {
    expect(GitLabProjectPath::fromUrl('https://gitlab.com.evil.com/group/project'))->toBeNull();
});

it('rejects subdomain spoofing — notgitlab.com', function (): void {
    expect(GitLabProjectPath::fromUrl('https://notgitlab.com/group/project'))->toBeNull();
});

it('rejects single-segment URL (namespace requires at least one segment)', function (): void {
    expect(GitLabProjectPath::fromUrl('https://gitlab.com/foo'))->toBeNull();
});

it('rejects root URL (no path)', function (): void {
    expect(GitLabProjectPath::fromUrl('https://gitlab.com/'))->toBeNull();
});

it('rejects /-/ browser URLs (PITFALL #6)', function (): void {
    expect(GitLabProjectPath::fromUrl('https://gitlab.com/gitlab-org/gitlab/-/tree/main'))->toBeNull();
});

it('rejects /-/ blob URLs', function (): void {
    expect(GitLabProjectPath::fromUrl('https://gitlab.com/gitlab-org/gitlab/-/blob/main/README.md'))->toBeNull();
});

it('accepts self-hosted host via the $host parameter', function (): void {
    $result = GitLabProjectPath::fromUrl('https://gitlab.example.com/group/project', 'gitlab.example.com');
    expect($result)->toBeInstanceOf(GitLabProjectPath::class);
    /** @var GitLabProjectPath $result */
    expect($result->namespace)->toBe('group')
        ->and($result->project)->toBe('project')
        ->and($result->encodedPath)->toBe('group%2Fproject');
});

it('rejects gitlab.example.com URL when default host=gitlab.com is used', function (): void {
    expect(GitLabProjectPath::fromUrl('https://gitlab.example.com/group/project'))->toBeNull();
});

it('exposes SourceTypes::GITLAB_RELEASES and SourceTypes::GITLAB_FILE constants', function (): void {
    expect(SourceTypes::GITLAB_RELEASES)->toBe('gitlab_releases')
        ->and(SourceTypes::GITLAB_FILE)->toBe('gitlab_file');
});
