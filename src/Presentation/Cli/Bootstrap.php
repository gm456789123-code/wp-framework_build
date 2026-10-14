<?php

declare(strict_types=1);

namespace App\Presentation\Cli;

use App\Domain\Event\DomainEventDispatcherInterface;
use App\Domain\Repository\AuditLoggerInterface;
use App\Domain\Repository\PageRepositoryInterface;
use App\Infrastructure\Env;
use App\Infrastructure\Events\SynchronousEventDispatcher;
use Closure;
use Redis;
use Throwable;

/**
 * Composition root for CLI commands: wires concrete Infrastructure adapters
 * for Application use cases to depend on through their interfaces.
 *
 * pageRepository()/auditLogger() return Lazy* wrappers rather than
 * connecting immediately: bin/console constructs every command up front so
 * Symfony can list/route them, and an eager connection there would make
 * every invocation (including `make:page`, which touches no database at
 * all) require reachable infrastructure. redisFactory() follows the same
 * rule for Redis: it hands back a closure for the *consuming command* to
 * call lazily, rather than connecting here.
 */
final class Bootstrap
{
    private static ?DomainEventDispatcherInterface $eventDispatcher = null;
    private static ?Redis $redis = null;
    private static bool $redisAttempted = false;

    public static function eventDispatcher(): DomainEventDispatcherInterface
    {
        return self::$eventDispatcher ??= new SynchronousEventDispatcher();
    }

    public static function pageRepository(): PageRepositoryInterface
    {
        return new LazyPageRepository();
    }

    public static function auditLogger(): AuditLoggerInterface
    {
        return new LazyAuditLogger();
    }

    /**
     * @return Closure(): ?Redis
     */
    public static function redisFactory(): Closure
    {
        return static fn (): ?Redis => self::redis();
    }

    private static function redis(): ?Redis
    {
        if (self::$redisAttempted) {
            return self::$redis;
        }

        self::$redisAttempted = true;

        if (!class_exists(Redis::class)) {
            return null;
        }

        try {
            $redis = new Redis();
            $host = Env::string('REDIS_HOST', 'redis');
            $port = Env::int('REDIS_PORT', 6379);
            $redis->connect($host, $port, 1.0);

            return self::$redis = $redis;
        } catch (Throwable) {
            return null;
        }
    }
}
