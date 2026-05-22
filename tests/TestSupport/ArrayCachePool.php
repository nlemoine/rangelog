<?php

declare(strict_types=1);

namespace n5s\Rangelog\Tests\TestSupport;

use DateInterval;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * In-memory PSR-6 stub for Psr6CacheAdapterTest.
 *
 * Test stub — does not implement real TTL expiry. Tests should
 * verify that TTL VALUES are forwarded to expiresAfter, not that
 * expiry happens after wall-clock time.
 *
 * Two final classes in one file is acceptable for test infrastructure.
 */
final class ArrayCachePool implements CacheItemPoolInterface
{
    /** @var array<string, ArrayCacheItem> */
    private array $items = [];

    /** @var array<string, ArrayCacheItem> */
    private array $deferred = [];

    public function getItem(string $key): CacheItemInterface
    {
        return $this->items[$key] ?? new ArrayCacheItem($key);
    }

    /**
     * @param array<string> $keys
     *
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->getItem($key);
        }

        return $result;
    }

    public function hasItem(string $key): bool
    {
        return isset($this->items[$key]) && $this->items[$key]->isHit();
    }

    public function clear(): bool
    {
        $this->items = [];
        $this->deferred = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    /**
     * @param array<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->deleteItem($key);
        }

        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (! $item instanceof ArrayCacheItem) {
            return false;
        }

        $item->hit = true;
        $this->items[$item->getKey()] = $item;

        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (! $item instanceof ArrayCacheItem) {
            return false;
        }

        $this->deferred[$item->getKey()] = $item;

        return true;
    }

    public function commit(): bool
    {
        foreach ($this->deferred as $item) {
            $this->save($item);
        }
        $this->deferred = [];

        return true;
    }
}

/**
 * In-memory PSR-6 cache item used by ArrayCachePool.
 *
 * Public mutable state ($value, $hit, $ttl, $expiresAfterCalled) is
 * intentional — tests inspect these fields directly to verify the
 * adapter forwarded TTL values to expiresAfter() correctly.
 */
final class ArrayCacheItem implements CacheItemInterface
{
    public mixed $value = null;

    public bool $hit = false;

    public int|DateInterval|null $ttl = null;

    /** Records whether expiresAfter() was called — null means not called, true means called. */
    public bool $expiresAfterCalled = false;

    public function __construct(private readonly string $key)
    {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->hit ? $this->value : null;
    }

    public function isHit(): bool
    {
        return $this->hit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function expiresAt(?DateTimeInterface $expiration): static
    {
        // Test stub — TTL not enforced; recorded for inspection only.
        return $this;
    }

    public function expiresAfter(int|DateInterval|null $time): static
    {
        $this->ttl = $time;
        $this->expiresAfterCalled = true;

        return $this;
    }
}
