<?php

namespace Modules\Analytics\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Cache::tags() throws on the file/database stores, so dashboard keys carry a
 * version prefix instead. Bumping the version invalidates everything at once;
 * orphaned entries just expire via TTL.
 */
class AnalyticsCache
{
    private const VERSION_KEY = 'analytics:version';

    public function remember(string $key, int $ttlSeconds, Closure $callback): mixed
    {
        return Cache::remember($this->versionedKey($key), $ttlSeconds, $callback);
    }

    public function flush(): void
    {
        // increment() creates the key if missing, so flushing before the first read is fine
        Cache::increment(self::VERSION_KEY);
    }

    private function versionedKey(string $key): string
    {
        $version = Cache::get(self::VERSION_KEY, 0);

        return "analytics:v{$version}:{$key}";
    }
}
