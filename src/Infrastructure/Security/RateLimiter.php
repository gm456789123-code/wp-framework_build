<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

/**
 * Fixed-window rate limiting with no external dependency (Redis is optional
 * in this architecture and silently disabled when unreachable -- a hard
 * security control must not inherit that same fail-open behaviour). Each
 * key gets its own small file under the storage directory; a flock()
 * around the read-modify-write makes the increment atomic across
 * concurrent PHP-FPM workers, the same concern MerkleAuditLogger solves
 * with MySQL's GET_LOCK, just file-based since this runs before any DB
 * connection is guaranteed to exist (e.g. pre-install).
 */
final class RateLimiter
{
    public function __construct(
        private readonly string $storageDir,
    ) {
    }

    /**
     * @return bool true if the caller has exceeded $maxAttempts within the
     *              last $windowSeconds and should be refused.
     */
    public function tooManyAttempts(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0755, true);
        }

        $path = $this->storageDir . '/' . hash('sha256', $key) . '.json';

        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            // Storage unavailable -- fail closed (refuse) rather than
            // silently letting every request through unlimited.
            return true;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return true;
            }

            $raw = stream_get_contents($handle);
            $now = time();

            /** @var array{windowStart: int, count: int}|null $state */
            $state = null;
            if (is_string($raw) && $raw !== '') {
                $decoded = self::decodeState($raw);
                if ($decoded !== null) {
                    $state = $decoded;
                }
            }

            if ($state === null || ($now - $state['windowStart']) >= $windowSeconds) {
                $state = ['windowStart' => $now, 'count' => 0];
            }

            $state['count']++;
            $exceeded = $state['count'] > $maxAttempts;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($state));
            fflush($handle);

            return $exceeded;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array{windowStart: int, count: int}|null
     * @psalm-suppress MixedAssignment
     */
    private static function decodeState(string $raw): ?array
    {
        $decoded = json_decode($raw, true);

        if (is_array($decoded) && isset($decoded['windowStart'], $decoded['count'])
            && is_int($decoded['windowStart']) && is_int($decoded['count'])
        ) {
            return ['windowStart' => $decoded['windowStart'], 'count' => $decoded['count']];
        }

        return null;
    }
}
