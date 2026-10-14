<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

class CacheService
{
    protected CacheRepository $cache;
    protected int $defaultTtl = 300;

    public function __construct(CacheRepository $cache = null)
    {
        $this->cache = $cache ?? Cache::store();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->cache->get($key, $default);
    }

    public function put(string $key, mixed $value, int $ttl = null): bool
    {
        return $this->cache->put($key, $value, $ttl ?? $this->defaultTtl);
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        return $this->cache->remember($key, $ttl, $callback);
    }

    public function rememberForever(string $key, callable $callback): mixed
    {
        return $this->cache->rememberForever($key, $callback);
    }

    public function forget(string $key): bool
    {
        return $this->cache->forget($key);
    }

    public function flush(): bool
    {
        return $this->cache->flush();
    }

    public function has(string $key): bool
    {
        return $this->cache->has($key);
    }

    public function increment(string $key, int $value = 1): int
    {
        return $this->cache->increment($key, $value);
    }

    public function decrement(string $key, int $value = 1): int
    {
        return $this->cache->decrement($key, $value);
    }

    public function tags(array|string $tags): \Illuminate\Cache\TaggedCache
    {
        return Cache::tags($tags);
    }

    public function withTags(array|string $tags): self
    {
        $this->cache = $this->cache->tags($tags);
        return $this;
    }

    public function setDefaultTtl(int $ttl): self
    {
        $this->defaultTtl = $ttl;
        return $this;
    }

    public function key(string $prefix, mixed ...$params): string
    {
        return $prefix . ':' . implode(':', array_map(fn($p) => is_scalar($p) ? $p : json_encode($p), $params));
    }
}