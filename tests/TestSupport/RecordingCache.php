<?php

declare(strict_types=1);

namespace n5s\Rangelog\Tests\TestSupport;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * In-memory PSR-16 cache stub that records every operation it sees.
 *
 * Used by CachingFetcher tests to assert cache keys, stored values,
 * and TTLs without depending on a real backend.
 *
 * @phpstan-type CacheOp array{op: string, key: string, value: mixed, ttl: null|int|DateInterval}
 */
final class RecordingCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $store = [];

    /** @var list<CacheOp> */
    public array $ops = [];

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ops[] = ['op' => 'get', 'key' => $key, 'value' => null, 'ttl' => null];

        return $this->store[$key] ?? $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->ops[] = ['op' => 'set', 'key' => $key, 'value' => $value, 'ttl' => $ttl];
        $this->store[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        $this->ops[] = ['op' => 'delete', 'key' => $key, 'value' => null, 'ttl' => null];
        unset($this->store[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->store = [];

        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->store[$key]);
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
