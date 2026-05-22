<?php

declare(strict_types=1);

use n5s\Rangelog\Domain\Package;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Domain\VersionRange;
use n5s\Rangelog\Exception\ChangelogNotFoundException;
use n5s\Rangelog\Exception\FetchException;
use n5s\Rangelog\Exception\RateLimitedException;
use n5s\Rangelog\SourceProvider\IterativeSourceProviderInterface;
use n5s\Rangelog\SourceProvider\SourceProviderChain;
use n5s\Rangelog\SourceProvider\SourceProviderInterface;
use n5s\Rangelog\Tests\TestSupport\ArrayLogger;

/**
 * Anonymous-class stub implementing SourceProviderInterface — counters expose
 * call patterns to the test.
 *
 * @return SourceProviderInterface&object{supportsCallCount: int, resolveCallCount: int}
 */
function stubProvider(
    bool $supports,
    ?Source $resolveResult = null,
    ?Throwable $supportsThrows = null,
    ?Throwable $resolveThrows = null,
): SourceProviderInterface {
    return new class ($supports, $resolveResult, $supportsThrows, $resolveThrows) implements SourceProviderInterface {
        public int $supportsCallCount = 0;
        public int $resolveCallCount = 0;

        public function __construct(
            private readonly bool $supportsResult,
            private readonly ?Source $resolveResult,
            private readonly ?Throwable $supportsThrows,
            private readonly ?Throwable $resolveThrows,
        ) {
        }

        public function supports(Package $package): bool
        {
            ++$this->supportsCallCount;
            if ($this->supportsThrows instanceof Throwable) {
                throw $this->supportsThrows;
            }

            return $this->supportsResult;
        }

        public function resolve(Package $package, VersionRange $range): Source
        {
            ++$this->resolveCallCount;
            if ($this->resolveThrows instanceof Throwable) {
                throw $this->resolveThrows;
            }
            if (!$this->resolveResult instanceof Source) {
                throw new LogicException('stubProvider not configured to return a Source');
            }

            return $this->resolveResult;
        }
    };
}

it('is a final class implementing SourceProviderInterface', function (): void {
    $reflection = new ReflectionClass(SourceProviderChain::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->implementsInterface(SourceProviderInterface::class))->toBeTrue();
});

it('constructs with list<SourceProviderInterface> providers and optional logger', function (): void {
    $chain = new SourceProviderChain([stubProvider(false)]);
    expect($chain)->toBeInstanceOf(SourceProviderChain::class);

    $chainWithLogger = new SourceProviderChain([stubProvider(false)], new ArrayLogger());
    expect($chainWithLogger)->toBeInstanceOf(SourceProviderChain::class);
});

it('supports() returns true when ANY inner provider supports', function (): void {
    $chain = new SourceProviderChain([
        stubProvider(false),
        stubProvider(true),
        stubProvider(false),
    ]);

    expect($chain->supports(new Package('symfony/console', 'https://github.com/symfony/console')))->toBeTrue();
});

it('supports() returns false when NO inner provider supports', function (): void {
    $chain = new SourceProviderChain([
        stubProvider(false),
        stubProvider(false),
    ]);

    expect($chain->supports(new Package('symfony/console', 'https://github.com/symfony/console')))->toBeFalse();
});

it('resolve() picks the FIRST provider whose supports() is true (injection order)', function (): void {
    $sourceA = new Source(type: 'github_releases', url: 'https://example.com/a');
    $sourceB = new Source(type: 'github_file', url: 'https://example.com/b');

    $first = stubProvider(false);
    $second = stubProvider(true, $sourceA);
    $third = stubProvider(true, $sourceB);

    $chain = new SourceProviderChain([$first, $second, $third]);
    $result = $chain->resolve(new Package('symfony/console', 'https://github.com/symfony/console'), VersionRange::changes('1.0.0', '2.0.0'));

    expect($result)->toBe($sourceA);
    expect($third->supportsCallCount)->toBe(0);
    expect($third->resolveCallCount)->toBe(0);
});

it('resolve() throws ChangelogNotFoundException when no provider supports', function (): void {
    $chain = new SourceProviderChain([
        stubProvider(false),
        stubProvider(false),
        stubProvider(false),
    ]);

    $caught = null;
    try {
        $chain->resolve(new Package('symfony/console', 'https://github.com/symfony/console'), VersionRange::changes('1.0.0', '2.0.0'));
    } catch (ChangelogNotFoundException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ChangelogNotFoundException::class);
    /** @var ChangelogNotFoundException $caught */
    expect($caught->getMessage())->toContain('symfony/console');
});

it('chain-of-chains: a SourceProviderChain works as an inner provider', function (): void {
    $sourceA = new Source(type: 'github_releases', url: 'https://example.com/a');

    $inner = new SourceProviderChain([
        stubProvider(false),
        stubProvider(true, $sourceA),
    ]);
    $outer = new SourceProviderChain([
        stubProvider(false),
        $inner,
    ]);

    $result = $outer->resolve(new Package('symfony/console', 'https://github.com/symfony/console'), VersionRange::changes('1.0.0', '2.0.0'));
    expect($result)->toBe($sourceA);
});

it('does NOT catch exceptions thrown by an inner provider supports()', function (): void {
    $chain = new SourceProviderChain([
        stubProvider(false, supportsThrows: new FetchException('boom', statusCode: 500)),
    ]);

    $chain->supports(new Package('symfony/console', 'https://github.com/symfony/console'));
})->throws(FetchException::class);

it('does NOT catch exceptions thrown by an inner provider resolve()', function (): void {
    $chain = new SourceProviderChain([
        stubProvider(true, resolveThrows: new RateLimitedException(message: 'rate limited')),
    ]);

    $chain->resolve(new Package('symfony/console', 'https://github.com/symfony/console'), VersionRange::changes('1.0.0', '2.0.0'));
})->throws(RateLimitedException::class);

it('emits PSR-3 log events for debug check + info resolved (happy path)', function (): void {
    $sourceA = new Source(type: 'github_releases', url: 'https://example.com/a');
    $logger = new ArrayLogger();
    $chain = new SourceProviderChain([
        stubProvider(false),
        stubProvider(true, $sourceA),
    ], $logger);

    $chain->resolve(new Package('symfony/console', 'https://github.com/symfony/console'), VersionRange::changes('1.0.0', '2.0.0'));

    $debug = array_values(array_filter(
        $logger->records,
        fn (array $r): bool => $r['level'] === 'debug' && str_contains($r['message'], 'Checking'),
    ));
    expect(count($debug))->toBeGreaterThanOrEqual(1);
    expect($debug[0]['context'])->toHaveKey('provider');
    expect($debug[0]['context'])->toHaveKey('package');

    $info = array_values(array_filter(
        $logger->records,
        fn (array $r): bool => $r['level'] === 'info' && $r['message'] === 'Resolved {package} via {provider}',
    ));
    expect(count($info))->toBe(1);
    expect($info[0]['context'])->toHaveKey('provider');
    expect($info[0]['context'])->toHaveKey('package');
    expect($info[0]['context'])->toHaveKey('source_type');
    expect($info[0]['context']['package'])->toBe('symfony/console');
    expect($info[0]['context']['source_type'])->toBe('github_releases');
});

it('emits PSR-3 warning when no provider supports', function (): void {
    $logger = new ArrayLogger();
    $chain = new SourceProviderChain([
        stubProvider(false),
        stubProvider(false),
    ], $logger);

    try {
        $chain->resolve(new Package('symfony/console', 'https://github.com/symfony/console'), VersionRange::changes('1.0.0', '2.0.0'));
    } catch (ChangelogNotFoundException) {
        // expected
    }

    $warnings = array_values(array_filter(
        $logger->records,
        fn (array $r): bool => $r['level'] === 'warning' && str_contains($r['message'], 'No provider in chain supports'),
    ));
    expect(count($warnings))->toBe(1);
    expect($warnings[0]['context'])->toHaveKey('package');
    expect($warnings[0]['context']['package'])->toBe('symfony/console');
});

it('implements IterativeSourceProviderInterface', function (): void {
    $reflection = new ReflectionClass(SourceProviderChain::class);
    expect($reflection->implementsInterface(IterativeSourceProviderInterface::class))->toBeTrue();
});

it('resolveAll() yields each SUPPORTING provider Source in injection order', function (): void {
    $sourceA = new Source(type: 'github_releases', url: 'https://example.com/a');
    $sourceB = new Source(type: 'github_file', url: 'https://example.com/b');

    $chain = new SourceProviderChain([
        stubProvider(false),
        stubProvider(true, $sourceA),
        stubProvider(true, $sourceB),
    ]);

    $pkg = new Package('symfony/console', 'https://github.com/symfony/console');
    $range = VersionRange::changes('1.0.0', '2.0.0');

    $results = iterator_to_array($chain->resolveAll($pkg, $range));

    expect($results)->toHaveCount(2);
    expect($results[0])->toBe($sourceA);
    expect($results[1])->toBe($sourceB);
});

it('resolveAll() throws ChangelogNotFoundException when NO provider supports', function (): void {
    $chain = new SourceProviderChain([
        stubProvider(false),
        stubProvider(false),
    ]);

    $pkg = new Package('symfony/console', 'https://github.com/symfony/console');
    $range = VersionRange::changes('1.0.0', '2.0.0');

    $caught = null;
    try {
        foreach ($chain->resolveAll($pkg, $range) as $_) {
            // consume the generator
        }
    } catch (ChangelogNotFoundException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ChangelogNotFoundException::class);
});

it('resolveAll() propagates exceptions thrown by inner resolve() verbatim', function (): void {
    $chain = new SourceProviderChain([
        stubProvider(true, resolveThrows: new RateLimitedException(message: 'rate limited')),
    ]);

    $pkg = new Package('symfony/console', 'https://github.com/symfony/console');
    $range = VersionRange::changes('1.0.0', '2.0.0');

    $caught = null;
    try {
        foreach ($chain->resolveAll($pkg, $range) as $_) {
            // consume the generator
        }
    } catch (RateLimitedException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RateLimitedException::class);
});

it('resolveAll() does NOT call resolve() on providers whose supports() returned false', function (): void {
    $sourceA = new Source(type: 'github_file', url: 'https://example.com/a');

    $first = stubProvider(false);
    $second = stubProvider(true, $sourceA);

    $chain = new SourceProviderChain([$first, $second]);

    $pkg = new Package('symfony/console', 'https://github.com/symfony/console');
    $range = VersionRange::changes('1.0.0', '2.0.0');

    iterator_to_array($chain->resolveAll($pkg, $range));

    expect($first->resolveCallCount)->toBe(0);
});
