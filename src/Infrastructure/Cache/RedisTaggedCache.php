<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

use Override;
use Redis;

final readonly class RedisTaggedCache implements CacheInterface
{
    public function __construct(
        private Redis $redis,
    ) {
    }

    public static function buildKey(string $tenant, string $entity, string $id): string
    {
        return sprintf('%s:%s:%s', $tenant, $entity, $id);
    }

    #[Override]
    public function get(string $key): ?string
    {
        /** @var string|false $value */
        $value = $this->redis->get($key);

        return is_string($value) ? $value : null;
    }

    #[Override]
    public function set(string $key, string $value, int $ttl = 3600, array $tags = []): void
    {
        $this->redis->setex($key, $ttl, $value);

        foreach ($tags as $tag) {
            $this->redis->sAdd('tag:' . $tag, $key);
            $this->redis->expire('tag:' . $tag, $ttl + 3600);
        }
    }

    #[Override]
    public function delete(string $key): void
    {
        $this->redis->del($key);
    }

    #[Override]
    public function invalidateTag(string $tag): void
    {
        $tagKey = 'tag:' . $tag;
        /** @var list<string> $keys */
        $keys = $this->redis->sMembers($tagKey);

        if (!empty($keys)) {
            $this->redis->del($keys);
        }

        $this->redis->del($tagKey);
    }

    /**
     * Helper generating L3 Cloudflare Edge SWR & Cache-Tag headers.
     *
     * @param list<string> $tags
     * @return array<string, string>
     */
    public function swrHeaders(int $maxAge = 60, int $staleWhileRevalidate = 300, array $tags = []): array
    {
        $headers = [
            'Cache-Control' => sprintf('public, max-age=%d, stale-while-revalidate=%d', $maxAge, $staleWhileRevalidate),
        ];

        if (!empty($tags)) {
            $headers['Cache-Tag'] = implode(',', $tags);
        }

        return $headers;
    }
}
