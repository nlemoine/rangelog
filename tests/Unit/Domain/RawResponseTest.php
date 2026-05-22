<?php

declare(strict_types=1);

use n5s\Rangelog\Domain\RawResponse;
use n5s\Rangelog\Domain\Source;

it('constructs with body, contentType, and Source', function (): void {
    $source = new Source(type: 'github_file', url: 'https://example.com/CHANGELOG.md');
    $response = new RawResponse(body: '# Changelog', contentType: 'text/markdown', source: $source);
    expect($response->body)->toBe('# Changelog');
    expect($response->contentType)->toBe('text/markdown');
    expect($response->source)->toBe($source);
});

it('preserves the body string exactly (buffered, not streaming)', function (): void {
    // body is a string, not a StreamInterface — read once, store as string.
    $body = str_repeat('A', 10_000);
    $source = new Source(type: 't', url: 'u');
    $response = new RawResponse(body: $body, contentType: 'text/plain', source: $source);
    expect($response->body)->toBe($body);
    expect(strlen($response->body))->toBe(10_000);
});

it('holds the originating Source instance', function (): void {
    $source = new Source(type: 'wordpress_org', url: 'https://plugins.svn.wordpress.org/akismet/trunk/readme.txt');
    $response = new RawResponse(body: '', contentType: 'text/plain', source: $source);
    expect($response->source->type)->toBe('wordpress_org');
});

it('is final and readonly', function (): void {
    $reflection = new ReflectionClass(RawResponse::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();
});
