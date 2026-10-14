<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

interface CacheInterface
{
    public function get(string $key): ?string;

    /**
     * @param list<string> $tags
     */
    public function set(string $key, string $value, int $ttl = 3600, array $tags = []): void;

    public function delete(string $key): void;

    public function invalidateTag(string $tag): void;
}
