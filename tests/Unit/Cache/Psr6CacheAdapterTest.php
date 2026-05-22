<?php

declare(strict_types=1);

use n5s\Rangelog\Cache\Psr6CacheAdapter;
use n5s\Rangelog\Tests\TestSupport\ArrayCacheItem;
use n5s\Rangelog\Tests\TestSupport\ArrayCachePool;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException as Psr6InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as Psr16InvalidArgumentException;

/**
 * Extract the stub ArrayCacheItem from a pool — narrows the PSR-6 return type
 * to the concrete stub so tests can inspect the recorded TTL fields. Fails the
 * test if the wrong type comes back (defensive: makes the type narrowing safe
 * without resorting to inline assertions or @var docblocks).
 */
function getArrayCacheItem(ArrayCachePool $pool, string $key): ArrayCacheItem
{
    $item = $pool->getItem($key);
    if (! $item instanceof ArrayCacheItem) {
        throw new LogicException('ArrayCachePool returned a non-ArrayCacheItem');
    }

    return $item;
}

/**
 * Build a PSR-6 pool whose every key-bearing method throws PSR-6's
 * InvalidArgumentException — used to verify the adapter wraps these
 * into PSR-16's InvalidArgumentException (Test 13).
 */
function throwingCachePool(): CacheItemPoolInterface
{
    return new class () implements CacheItemPoolInterface {
        private function bad(): Throwable
        {
            return new class ('bad key', 0) extends \InvalidArgumentException implements Psr6InvalidArgumentException {};
        }

        public function getItem(string $key): CacheItemInterface
        {
            throw $this->bad();
        }

        /**
         * @param array<string> $keys
         *
         * @return iterable<string, CacheItemInterface>
         */
        public function getItems(array $keys = []): iterable
        {
            throw $this->bad();
        }

        public function hasItem(string $key): bool
        {
            throw $this->bad();
        }

        public function clear(): bool
        {
            throw new BadMethodCallException('clear() not exercised in throwing-pool tests');
        }

        public function deleteItem(string $key): bool
        {
            throw $this->bad();
        }

        /**
         * @param array<string> $keys
         */
        public function deleteItems(array $keys): bool
        {
            throw $this->bad();
        }

        public function save(CacheItemInterface $item): bool
        {
            throw new BadMethodCallException('save() not exercised in throwing-pool tests');
        }

        public function saveDeferred(CacheItemInterface $item): bool
        {
            throw new BadMethodCallException('saveDeferred() not exercised in throwing-pool tests');
        }

        public function commit(): bool
        {
            throw new BadMethodCallException('commit() not exercised in throwing-pool tests');
        }
    };
}

it('is a final class implementing Psr\\SimpleCache\\CacheInterface', function (): void {
    $reflection = new ReflectionClass(Psr6CacheAdapter::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->implementsInterface(CacheInterface::class))->toBeTrue();
});

it('returns the default for a cache miss on get()', function (): void {
    $adapter = new Psr6CacheAdapter(new ArrayCachePool());
    expect($adapter->get('missing', 'default-value'))->toBe('default-value');
});

it('returns the stored value on cache hit via get()', function (): void {
    $adapter = new Psr6CacheAdapter(new ArrayCachePool());
    $adapter->set('key1', 'hello world');
    expect($adapter->get('key1'))->toBe('hello world');
});

it('stores values via set() and forwards int TTL to expiresAfter', function (): void {
    $pool = new ArrayCachePool();
    $adapter = new Psr6CacheAdapter($pool);
    $adapter->set('key-int-ttl', 'value', 60);

    $item = getArrayCacheItem($pool, 'key-int-ttl');
    expect($item->ttl)->toBe(60);
    expect($item->expiresAfterCalled)->toBeTrue();
});

it('stores values via set() with DateInterval TTL', function (): void {
    $pool = new ArrayCachePool();
    $adapter = new Psr6CacheAdapter($pool);
    $interval = new DateInterval('PT2H');
    $adapter->set('key-interval-ttl', 'value', $interval);

    $item = getArrayCacheItem($pool, 'key-interval-ttl');
    expect($item->ttl)->toBe($interval);
    expect($item->expiresAfterCalled)->toBeTrue();
});

it('stores values via set() with null TTL meaning no expiration', function (): void {
    $pool = new ArrayCachePool();
    $adapter = new Psr6CacheAdapter($pool);
    $adapter->set('key-null-ttl', 'value');

    $item = getArrayCacheItem($pool, 'key-null-ttl');
    // Null TTL means do NOT call expiresAfter at all, so the underlying
    // pool/backend uses its default (no expiration).
    expect($item->expiresAfterCalled)->toBeFalse();
    expect($item->ttl)->toBeNull();
});

it('returns true from has() when item exists, false otherwise', function (): void {
    $adapter = new Psr6CacheAdapter(new ArrayCachePool());
    expect($adapter->has('missing'))->toBeFalse();
    $adapter->set('present', 'x');
    expect($adapter->has('present'))->toBeTrue();
});

it('removes via delete() and returns true', function (): void {
    $adapter = new Psr6CacheAdapter(new ArrayCachePool());
    $adapter->set('to-delete', 'x');
    expect($adapter->has('to-delete'))->toBeTrue();
    expect($adapter->delete('to-delete'))->toBeTrue();
    expect($adapter->has('to-delete'))->toBeFalse();
});

it('clears the pool via clear()', function (): void {
    $adapter = new Psr6CacheAdapter(new ArrayCachePool());
    $adapter->set('a', 1);
    $adapter->set('b', 2);
    expect($adapter->clear())->toBeTrue();
    expect($adapter->has('a'))->toBeFalse();
    expect($adapter->has('b'))->toBeFalse();
});

it('handles getMultiple() returning a key=>value map with default for misses', function (): void {
    $adapter = new Psr6CacheAdapter(new ArrayCachePool());
    $adapter->set('hit1', 'value-1');
    $adapter->set('hit2', 'value-2');

    /** @var iterable<string, mixed> $result */
    $result = $adapter->getMultiple(['hit1', 'miss', 'hit2'], 'DEFAULT');
    $resultArray = is_array($result) ? $result : iterator_to_array($result);

    expect($resultArray)->toMatchArray([
        'hit1' => 'value-1',
        'miss' => 'DEFAULT',
        'hit2' => 'value-2',
    ]);
});

it('handles setMultiple() over an iterable of key=>value pairs', function (): void {
    $adapter = new Psr6CacheAdapter(new ArrayCachePool());
    expect($adapter->setMultiple(['k1' => 'v1', 'k2' => 'v2', 'k3' => 'v3']))->toBeTrue();
    expect($adapter->get('k1'))->toBe('v1');
    expect($adapter->get('k2'))->toBe('v2');
    expect($adapter->get('k3'))->toBe('v3');
});

it('handles deleteMultiple() over an iterable of keys', function (): void {
    $adapter = new Psr6CacheAdapter(new ArrayCachePool());
    $adapter->set('k1', 'v1');
    $adapter->set('k2', 'v2');
    $adapter->set('k3', 'v3');

    expect($adapter->deleteMultiple(['k1', 'k3']))->toBeTrue();
    expect($adapter->has('k1'))->toBeFalse();
    expect($adapter->has('k2'))->toBeTrue();
    expect($adapter->has('k3'))->toBeFalse();
});

/**
 * Pest's toThrow() falls back to a string-contains assertion when the argument
 * is not a class (interfaces return false from class_exists). Psr-16's
 * InvalidArgumentException IS an interface, so we use catch + instanceof
 * directly to assert the contract.
 *
 * @param callable(): mixed $fn
 */
function assertThrowsPsr16InvalidArg(callable $fn): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf(Psr16InvalidArgumentException::class);

        return $e;
    }

    throw new RuntimeException('Expected Psr16InvalidArgumentException was not thrown');
}

it('translates Psr\\Cache\\InvalidArgumentException to Psr\\SimpleCache\\InvalidArgumentException on get()', function (): void {
    $adapter = new Psr6CacheAdapter(throwingCachePool());
    assertThrowsPsr16InvalidArg(fn (): mixed => $adapter->get('any-key'));
});

it('translates Psr\\Cache\\InvalidArgumentException to Psr\\SimpleCache\\InvalidArgumentException on set()', function (): void {
    $adapter = new Psr6CacheAdapter(throwingCachePool());
    assertThrowsPsr16InvalidArg(fn (): bool => $adapter->set('any-key', 'value'));
});

it('translates Psr\\Cache\\InvalidArgumentException to Psr\\SimpleCache\\InvalidArgumentException on has()', function (): void {
    $adapter = new Psr6CacheAdapter(throwingCachePool());
    assertThrowsPsr16InvalidArg(fn (): bool => $adapter->has('any-key'));
});

it('translates Psr\\Cache\\InvalidArgumentException to Psr\\SimpleCache\\InvalidArgumentException on delete()', function (): void {
    $adapter = new Psr6CacheAdapter(throwingCachePool());
    assertThrowsPsr16InvalidArg(fn (): bool => $adapter->delete('any-key'));
});

it('preserves the original PSR-6 exception as the previous when translating', function (): void {
    $adapter = new Psr6CacheAdapter(throwingCachePool());
    $caught = null;
    try {
        $adapter->get('any-key');
    } catch (Psr16InvalidArgumentException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(Psr16InvalidArgumentException::class);
    expect($caught?->getPrevious())->toBeInstanceOf(Psr6InvalidArgumentException::class);
});
