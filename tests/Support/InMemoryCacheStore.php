<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Tests\Support;

use Glueful\Cache\CacheStore;

/**
 * Minimal in-memory CacheStore for exercising the JWKS-caching path without a real cache backend.
 * Only get()/set()/has()/delete() carry behaviour the tests rely on; the remaining contract methods
 * are inert stubs (the JWKS cache only uses get/set).
 *
 * @implements CacheStore<mixed>
 */
final class InMemoryCacheStore implements CacheStore
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$key] ?? $default;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->store[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
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
        return array_key_exists($key, $this->store);
    }

    /**
     * @param iterable<mixed> $keys
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[(string) $key] = $this->get((string) $key, $default);
        }

        return $out;
    }

    /**
     * @param iterable<mixed, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    /**
     * @param iterable<mixed> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete((string) $key);
        }

        return true;
    }

    public function setNx(string $key, mixed $value, int $ttl = 3600): bool
    {
        if ($this->has($key)) {
            return false;
        }

        return $this->set($key, $value, $ttl);
    }

    /**
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public function mget(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function mset(array $values, int $ttl = 3600): bool
    {
        return $this->setMultiple($values, $ttl);
    }

    public function increment(string $key, int $value = 1): int
    {
        $current = (int) ($this->store[$key] ?? 0);
        $current += $value;
        $this->store[$key] = $current;

        return $current;
    }

    public function decrement(string $key, int $value = 1): int
    {
        return $this->increment($key, -$value);
    }

    public function ttl(string $key): int
    {
        return 0;
    }

    public function flush(): bool
    {
        return $this->clear();
    }

    /**
     * @param array<string, int|float> $scoreValues
     */
    public function zadd(string $key, array $scoreValues): bool
    {
        return true;
    }

    public function zremrangebyscore(string $key, string $min, string $max): int
    {
        return 0;
    }

    public function zcard(string $key): int
    {
        return 0;
    }

    /**
     * @return list<string>
     */
    public function zrange(string $key, int $start, int $stop): array
    {
        return [];
    }

    public function expire(string $key, int $seconds): bool
    {
        return true;
    }

    public function del(string $key): bool
    {
        return $this->delete($key);
    }

    public function deletePattern(string $pattern): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function getKeys(string $pattern = '*'): array
    {
        return array_keys($this->store);
    }

    /**
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public function getAllKeys(): array
    {
        return array_keys($this->store);
    }

    public function getKeyCount(string $pattern = '*'): int
    {
        return count($this->store);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCapabilities(): array
    {
        return [];
    }

    /**
     * @param list<string> $tags
     */
    public function addTags(string $key, array $tags): bool
    {
        return true;
    }

    /**
     * @param list<string> $tags
     */
    public function invalidateTags(array $tags): bool
    {
        return true;
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }
}
