<?php

declare(strict_types=1);

namespace n5s\Rangelog\Cache;

use DateInterval;
use InvalidArgumentException as PhpInvalidArgumentException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException as Psr6InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as Psr16InvalidArgumentException;

/**
 * PSR-6 → PSR-16 bridge.
 *
 * Wraps a caller-supplied CacheItemPoolInterface (PSR-6) and exposes
 * it as CacheInterface (PSR-16). Used by callers whose framework
 * provides a PSR-6 pool (Symfony Cache, Doctrine Cache) but who want
 * to use CachingFetcher's PSR-16-only constructor.
 *
 * Translation table:
 *   PSR-16 method        → PSR-6 call
 *   ─────────────────────────────────────
 *   get(key, default)    → getItem(key) ; isHit() ? get() : default
 *   set(key, val, ttl)   → getItem(key) ; set(val) ; expiresAfter(ttl) ; save(item)
 *   has(key)             → hasItem(key)
 *   delete(key)          → deleteItem(key)
 *   clear()              → clear()
 *   getMultiple(keys)    → getItems(keys) ; foreach with isHit/get/default
 *   setMultiple(values)  → loop set()
 *   deleteMultiple(keys) → deleteItems(keys)
 *
 * Exception translation: PSR-6's Psr\Cache\InvalidArgumentException is
 * caught at every key-bearing public-method boundary and rethrown as
 * PSR-16's Psr\SimpleCache\InvalidArgumentException. An anonymous-class
 * is used to avoid shipping a new named exception type for one internal
 * translation site.
 */
final readonly class Psr6CacheAdapter implements CacheInterface
{
    public function __construct(
        private CacheItemPoolInterface $pool,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $item = $this->pool->getItem($key);
        } catch (Psr6InvalidArgumentException $e) {
            throw $this->wrap($e);
        }

        return $item->isHit() ? $item->get() : $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        try {
            $item = $this->pool->getItem($key);
        } catch (Psr6InvalidArgumentException $e) {
            throw $this->wrap($e);
        }

        $item->set($value);
        if ($ttl !== null) {
            $item->expiresAfter($ttl);
        }

        return $this->pool->save($item);
    }

    public function delete(string $key): bool
    {
        try {
            return $this->pool->deleteItem($key);
        } catch (Psr6InvalidArgumentException $e) {
            throw $this->wrap($e);
        }
    }

    public function clear(): bool
    {
        return $this->pool->clear();
    }

    public function has(string $key): bool
    {
        try {
            return $this->pool->hasItem($key);
        } catch (Psr6InvalidArgumentException $e) {
            throw $this->wrap($e);
        }
    }

    /**
     * @param iterable<string> $keys
     *
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keysArray = $this->iterableToStringArray($keys);

        try {
            $items = $this->pool->getItems($keysArray);
        } catch (Psr6InvalidArgumentException $e) {
            throw $this->wrap($e);
        }

        $result = [];
        foreach ($items as $item) {
            // PSR-6's getItems() is typed `iterable` without generics; narrow
            // defensively to CacheItemInterface to satisfy the typed surface.
            if (! $item instanceof CacheItemInterface) {
                continue;
            }
            $result[$item->getKey()] = $item->isHit() ? $item->get() : $default;
        }

        return $result;
    }

    /**
     * @param iterable<mixed> $values
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            // PSR-16 declares `iterable $values` (no generics); PHP iteration
            // keys are always int|string but PHPStan-strict sees `mixed`.
            // Narrow explicitly so the PSR-6 key contract (string) is honoured.
            if (\is_string($key)) {
                $stringKey = $key;
            } elseif (\is_int($key)) {
                $stringKey = (string) $key;
            } else {
                continue; // unreachable under PHP semantics; defensive guard
            }
            $ok = $this->set($stringKey, $value, $ttl) && $ok;
        }

        return $ok;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $keysArray = $this->iterableToStringArray($keys);

        try {
            return $this->pool->deleteItems($keysArray);
        } catch (Psr6InvalidArgumentException $e) {
            throw $this->wrap($e);
        }
    }

    /**
     * @param iterable<string> $keys
     *
     * @return array<int, string>
     */
    private function iterableToStringArray(iterable $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[] = $key;
        }

        return $result;
    }

    private function wrap(Psr6InvalidArgumentException $e): Psr16InvalidArgumentException
    {
        // Anonymous class avoids adding a new public exception type for one
        // internal translation. Extends \InvalidArgumentException for the
        // \Throwable contract and implements PSR-16's InvalidArgumentException
        // marker interface so callers can catch it under the PSR-16 contract.
        return new class ($e->getMessage(), 0, $e) extends PhpInvalidArgumentException implements Psr16InvalidArgumentException {};
    }
}
