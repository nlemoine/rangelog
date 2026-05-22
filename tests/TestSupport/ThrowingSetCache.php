<?php

declare(strict_types=1);

namespace n5s\Rangelog\Tests\TestSupport;

use DateInterval;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

/**
 * PSR-16 cache stub whose {@see self::set()} always throws.
 *
 * Used by CachingFetcher tests to verify the best-effort cache-write
 * contract: cache failures must be logged at `warning` and swallowed,
 * never propagated to callers.
 */
final class ThrowingSetCache implements CacheInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        throw new RuntimeException('cache backend exploded');
    }

    public function delete(string $key): bool
    {
        return true;
    }

    public function clear(): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        return false;
    }

    /** @return iterable<string, mixed> */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return [];
    }

    /**
     * @param iterable<mixed, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return true;
    }
}
