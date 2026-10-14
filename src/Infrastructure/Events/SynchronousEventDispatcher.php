<?php

declare(strict_types=1);

namespace App\Infrastructure\Events;

use App\Domain\Event\DomainEventDispatcherInterface;
use Override;

/**
 * In-process dispatcher used until the Redis Streams producer (section 7 of
 * agents_md.md) lands. That future adapter will wrap this one as its
 * synchronous fallback for when Redis/the worker is unavailable.
 */
final class SynchronousEventDispatcher implements DomainEventDispatcherInterface
{
    /**
     * @var array<class-string, list<callable(object): void>>
     */
    private array $listeners = [];

    /**
     * @param class-string $eventClass
     * @param callable(object): void $listener
     */
    public function addListener(string $eventClass, callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    #[Override]
    public function dispatch(object $event): void
    {
        foreach ($this->listeners[$event::class] ?? [] as $listener) {
            $listener($event);
        }
    }
}
