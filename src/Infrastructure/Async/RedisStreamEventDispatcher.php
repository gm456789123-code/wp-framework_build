<?php

declare(strict_types=1);

namespace App\Infrastructure\Async;

use App\Domain\Event\DomainEventDispatcherInterface;
use App\Infrastructure\Events\SynchronousEventDispatcher;
use Override;
use Redis;
use Throwable;

final class RedisStreamEventDispatcher implements DomainEventDispatcherInterface
{
    public const string STREAM_NAME = 'stream:domain_events';
    public const string HEARTBEAT_KEY = 'worker:heartbeat';
    public const int HEARTBEAT_THRESHOLD_SECONDS = 60;

    public function __construct(
        private ?Redis $redis,
        private SynchronousEventDispatcher $fallbackDispatcher,
    ) {
    }

    #[Override]
    public function dispatch(object $event): void
    {
        if ($this->shouldFallback()) {
            $this->fallbackDispatcher->dispatch($event);

            return;
        }

        try {
            /** @var Redis $redis */
            $redis = $this->redis;
            $payload = json_encode($event, JSON_THROW_ON_ERROR);

            $redis->xAdd(self::STREAM_NAME, '*', [
                'event_class' => $event::class,
                'payload' => $payload,
                'created_at' => (string) microtime(true),
            ]);
        } catch (Throwable) {
            // Circuit breaker tripped: immediately fallback to synchronous execution
            $this->fallbackDispatcher->dispatch($event);
        }
    }

    /**
     * Checks if Redis or Worker heartbeat is offline/stale.
     */
    public function shouldFallback(): bool
    {
        if ($this->redis === null) {
            return true;
        }

        try {
            $heartbeat = $this->redis->get(self::HEARTBEAT_KEY);
            if (!is_string($heartbeat)) {
                return true;
            }

            $lastBeat = (int) $heartbeat;

            return (time() - $lastBeat) > self::HEARTBEAT_THRESHOLD_SECONDS;
        } catch (Throwable) {
            return true;
        }
    }

    public function recordHeartbeat(): void
    {
        if ($this->redis !== null) {
            $this->redis->setex(self::HEARTBEAT_KEY, self::HEARTBEAT_THRESHOLD_SECONDS + 30, (string) time());
        }
    }
}
