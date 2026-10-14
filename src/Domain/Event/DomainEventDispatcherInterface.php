<?php

declare(strict_types=1);

namespace App\Domain\Event;

interface DomainEventDispatcherInterface
{
    public function dispatch(object $event): void;
}
