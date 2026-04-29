<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Simple file-based cache using serialized PHP values.
 *
 * Cache files are stored in a configurable directory (default: APP_ROOT/cache).
 * Each entry is a single file named by a hashed key.
 */
class CacheService
{
    private string $cacheDir;

    public function __construct(?string $cacheDir = null)
    {
        $this->cacheDir = rtrim($cacheDir ?? (defined('APP_ROOT') ? APP_ROOT . '/cache' : sys_get_temp_dir() . '/nigats_cache'), '/\\');
    }

    /**
     * Fetch a value from cache, or compute and store it.
     *
     * @template T
     * @param string   $key      Cache key (arbitrary string)
     * @param int      $ttl      Time-to-live in seconds
     * @param callable $compute  Called when cache is cold; must return the value to cache
     * @return T
     */
    public function remember(string $key, int $ttl, callable $compute): mixed
    {
        $file = $this->filePath($key);

        if (file_exists($file)) {
            $raw = file_get_contents($file);
            if ($raw !== false) {
                $entry = unserialize($raw);
                if (is_array($entry) && isset($entry['expires_at'], $entry['value']) && time() < $entry['expires_at']) {
                    return $entry['value'];
                }
            }
        }

        $value = $compute();
        $this->store($key, $value, $ttl);
        return $value;
    }

    /**
     * Explicitly invalidate a cache entry.
     */
    public function forget(string $key): void
    {
        $file = $this->filePath($key);
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    /**
     * Invalidate all cache entries whose key starts with the given prefix.
     */
    public function forgetByPrefix(string $prefix): void
    {
        if (!is_dir($this->cacheDir)) {
            return;
        }

        $hashedPrefix = substr(hash('sha256', $prefix), 0, 8);
        foreach (glob($this->cacheDir . '/*.cache') ?: [] as $file) {
            $meta = $this->readMeta($file);
            if ($meta !== null && str_starts_with($meta['key'], $prefix)) {
                @unlink($file);
            }
        }
    }

    // -------------------------------------------------------------------------

    private function filePath(string $key): string
    {
        return $this->cacheDir . '/' . hash('sha256', $key) . '.cache';
    }

    private function store(string $key, mixed $value, int $ttl): void
    {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0750, true);
        }

        $entry = [
            'key'        => $key,
            'expires_at' => time() + $ttl,
            'value'      => $value,
        ];

        file_put_contents($this->filePath($key), serialize($entry), LOCK_EX);
    }

    private function readMeta(string $file): ?array
    {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $entry = @unserialize($raw);
        return is_array($entry) ? $entry : null;
    }
}
