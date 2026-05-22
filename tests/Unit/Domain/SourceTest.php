<?php

declare(strict_types=1);

use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Domain\SourceTypes;

it('constructs with type, url, metadata=[] by default', function (): void {
    $source = new Source(type: 'github_releases', url: 'https://api.github.com/repos/foo/bar/releases');
    expect($source->type)->toBe('github_releases');
    expect($source->url)->toBe('https://api.github.com/repos/foo/bar/releases');
    expect($source->metadata)->toBe([]);
});

it('accepts an arbitrary string as type (open string)', function (): void {
    $source = new Source(type: 'my_internal_v1', url: 'https://example.com/cl');
    expect($source->type)->toBe('my_internal_v1');
});

it('accepts a SourceTypes constant as type', function (): void {
    $source = new Source(type: SourceTypes::GITHUB_RELEASES, url: 'https://api.github.com/repos/foo/bar/releases');
    expect($source->type)->toBe('github_releases');
});

it('accepts metadata as associative array', function (): void {
    $source = new Source(type: 'wordpress_org', url: 'https://plugins.svn.wordpress.org/akismet/trunk/readme.txt', metadata: ['slug' => 'akismet']);
    expect($source->metadata)->toBe(['slug' => 'akismet']);
});

it('is final and readonly', function (): void {
    $reflection = new ReflectionClass(Source::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();
});

// ---------------------------------------------------------------------------
// prefetchedBody optional field
// ---------------------------------------------------------------------------

it('accepts an optional prefetchedBody constructor parameter', function (): void {
    $source = new Source(
        type: 'x',
        url: 'y',
        metadata: [],
        prefetchedBody: 'BODY',
    );
    expect($source->prefetchedBody)->toBe('BODY');
});

it('defaults prefetchedBody to null', function (): void {
    $source = new Source(type: 'x', url: 'y');
    expect($source->prefetchedBody)->toBeNull();
});
