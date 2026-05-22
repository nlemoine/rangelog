<?php

declare(strict_types=1);

use n5s\Rangelog\Domain\RawResponse;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Fetcher\FetcherInterface;
use n5s\Rangelog\Fetcher\FetcherStack;

/**
 * Build an in-test FetcherInterface that returns a RawResponse whose body
 * is a fixed string — useful as a "base" for stack composition tests.
 */
function stubFetcher(string $bodyMarker): FetcherInterface
{
    return new readonly class ($bodyMarker) implements FetcherInterface {
        public function __construct(private string $marker)
        {
        }

        public function fetch(Source $source): RawResponse
        {
            return new RawResponse(body: $this->marker, contentType: 'text/plain', source: $source);
        }
    };
}

/**
 * Build a FetcherInterface decorator that wraps an inner fetcher and
 * appends a tag to the body — proves call order.
 *
 * @return callable(FetcherInterface): FetcherInterface
 */
function tagDecorator(string $tag): callable
{
    return fn (FetcherInterface $inner): FetcherInterface => new readonly class ($inner, $tag) implements FetcherInterface {
        public function __construct(
            private FetcherInterface $inner,
            private string $tag,
        ) {
        }

        public function fetch(Source $source): RawResponse
        {
            $inner = $this->inner->fetch($source);

            return new RawResponse(
                body: $inner->body . '|' . $this->tag,
                contentType: $inner->contentType,
                source: $inner->source,
            );
        }
    };
}

it('delegates to base when no decorators are provided', function (): void {
    $base = stubFetcher('BASE');
    $stack = new FetcherStack(base: $base);
    $source = new Source(type: 'github_releases', url: 'https://example.com');
    $response = $stack->fetch($source);
    expect($response->body)->toBe('BASE');
});

it('wraps base with a single decorator', function (): void {
    $base = stubFetcher('BASE');
    $stack = new FetcherStack(
        base: $base,
        decorators: [tagDecorator('A')],
    );
    $source = new Source(type: 'github_releases', url: 'https://example.com');
    $response = $stack->fetch($source);
    // Single decorator wraps base, so output is base body then |A.
    expect($response->body)->toBe('BASE|A');
});

it('walks decorators in array order so the last decorator is the outermost wrapper', function (): void {
    $base = stubFetcher('BASE');
    $stack = new FetcherStack(
        base: $base,
        decorators: [
            tagDecorator('A'),  // innermost wrapper of base
            tagDecorator('B'),
            tagDecorator('C'),  // outermost wrapper
        ],
    );
    $source = new Source(type: 'github_releases', url: 'https://example.com');
    $response = $stack->fetch($source);
    // base→A→B→C: each tag is appended at its decorator level on call
    // unwound from outermost. The final body reads BASE|A|B|C in call order
    // because A wraps base first (closest to it) so A appends first, etc.
    expect($response->body)->toBe('BASE|A|B|C');
});

it('preserves the Source through composition', function (): void {
    $base = stubFetcher('BASE');
    $stack = new FetcherStack(
        base: $base,
        decorators: [tagDecorator('A')],
    );
    $source = new Source(type: 'wordpress_org', url: 'https://plugins.svn.wordpress.org/akismet/trunk/readme.txt');
    $response = $stack->fetch($source);
    expect($response->source)->toBe($source);
    expect($response->source->type)->toBe('wordpress_org');
});

it('is a final class', function (): void {
    $reflection = new ReflectionClass(FetcherStack::class);
    expect($reflection->isFinal())->toBeTrue();
});

it('implements FetcherInterface', function (): void {
    $reflection = new ReflectionClass(FetcherStack::class);
    expect($reflection->implementsInterface(FetcherInterface::class))->toBeTrue();
});

it('can be type-hinted as FetcherInterface (Liskov)', function (): void {
    $stack = new FetcherStack(base: stubFetcher('BASE'));
    // If FetcherStack implements FetcherInterface correctly, this assignment
    // works at runtime AND PHPStan-validates statically.
    $promoted = (static fn (FetcherInterface $f): FetcherInterface => $f)($stack);
    expect($promoted)->toBeInstanceOf(FetcherInterface::class);
});
