<?php

declare(strict_types=1);

use n5s\Rangelog\Domain\Package;

it('is final and readonly', function (): void {
    $reflection = new ReflectionClass(Package::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Happy paths
// ---------------------------------------------------------------------------

it('accepts a bare ecosystem name + GitHub URL', function (): void {
    $p = new Package('react', 'https://github.com/facebook/react');
    expect($p->name)->toBe('react');
    expect($p->sourceUrl)->toBe('https://github.com/facebook/react');
});

it('accepts a Cargo-style name', function (): void {
    $p = new Package('serde', 'https://github.com/serde-rs/serde');
    expect($p->name)->toBe('serde');
    expect($p->sourceUrl)->toBe('https://github.com/serde-rs/serde');
});

it('accepts a scoped npm name', function (): void {
    $p = new Package('@scope/pkg', 'https://github.com/scope/pkg');
    expect($p->name)->toBe('@scope/pkg');
    expect($p->sourceUrl)->toBe('https://github.com/scope/pkg');
});

it('accepts a Go module path', function (): void {
    $p = new Package('gopkg.in/yaml.v3', 'https://github.com/go-yaml/yaml');
    expect($p->name)->toBe('gopkg.in/yaml.v3');
    expect($p->sourceUrl)->toBe('https://github.com/go-yaml/yaml');
});

it('accepts a Maven coordinate', function (): void {
    $p = new Package('com.foo:bar', 'https://github.com/foo/bar');
    expect($p->name)->toBe('com.foo:bar');
    expect($p->sourceUrl)->toBe('https://github.com/foo/bar');
});

it('accepts a Composer vendor/package name', function (): void {
    $p = new Package('vendor/package', 'https://github.com/vendor/package');
    expect($p->name)->toBe('vendor/package');
    expect($p->sourceUrl)->toBe('https://github.com/vendor/package');
});

it('accepts http:// URL', function (): void {
    $p = new Package('react', 'http://github.com/facebook/react');
    expect($p->name)->toBe('react');
    expect($p->sourceUrl)->toBe('http://github.com/facebook/react');
});

it('accepts URL with query string', function (): void {
    $p = new Package('react', 'https://github.com/facebook/react?tab=releases');
    expect($p->sourceUrl)->toBe('https://github.com/facebook/react?tab=releases');
});

it('accepts URL with fragment', function (): void {
    $p = new Package('react', 'https://github.com/facebook/react#changelog');
    expect($p->sourceUrl)->toBe('https://github.com/facebook/react#changelog');
});

it('accepts localhost URL', function (): void {
    $p = new Package('app', 'http://localhost');
    expect($p->name)->toBe('app');
    expect($p->sourceUrl)->toBe('http://localhost');
});

it('accepts uppercase HTTP scheme via strtolower normalization', function (): void {
    $p = new Package('react', 'HTTPS://GITHUB.COM/test');
    expect($p->name)->toBe('react');
    expect($p->sourceUrl)->toBe('HTTPS://GITHUB.COM/test');
});

// ---------------------------------------------------------------------------
// Rejection paths
// ---------------------------------------------------------------------------

it('throws on empty name', function (): void {
    expect(fn (): Package => new Package('', 'https://example.com'))->toThrow(InvalidArgumentException::class);
});

it('throws on leading whitespace in name', function (): void {
    expect(fn (): Package => new Package(' react', 'https://example.com'))->toThrow(InvalidArgumentException::class);
});

it('throws on trailing whitespace in name', function (): void {
    expect(fn (): Package => new Package('react ', 'https://example.com'))->toThrow(InvalidArgumentException::class);
});

it('throws on non-URL sourceUrl', function (): void {
    expect(fn (): Package => new Package('react', 'not-a-url'))->toThrow(InvalidArgumentException::class);
});

it('throws on ftp:// scheme (whitelist enforces http/https)', function (): void {
    expect(fn (): Package => new Package('react', 'ftp://example.com'))->toThrow(InvalidArgumentException::class);
});

it('throws on file:// scheme (host check rejects null-host file scheme)', function (): void {
    expect(fn (): Package => new Package('react', 'file:///etc/passwd'))->toThrow(InvalidArgumentException::class);
});

it('throws on mailto: scheme', function (): void {
    expect(fn (): Package => new Package('react', 'mailto:foo@bar.com'))->toThrow(InvalidArgumentException::class);
});

it('throws on https:// with no host', function (): void {
    expect(fn (): Package => new Package('react', 'https://'))->toThrow(InvalidArgumentException::class);
});

it('throws on empty sourceUrl', function (): void {
    expect(fn (): Package => new Package('react', ''))->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// Removed methods are gone
// ---------------------------------------------------------------------------

it('does not have isWordPress method', function (): void {
    expect((new ReflectionClass(Package::class))->hasMethod('isWordPress'))->toBeFalse();
});

it('does not have getWordPressSlug method', function (): void {
    expect((new ReflectionClass(Package::class))->hasMethod('getWordPressSlug'))->toBeFalse();
});

it('does not have getVendor method', function (): void {
    expect((new ReflectionClass(Package::class))->hasMethod('getVendor'))->toBeFalse();
});

it('does not have getShortName method', function (): void {
    expect((new ReflectionClass(Package::class))->hasMethod('getShortName'))->toBeFalse();
});
