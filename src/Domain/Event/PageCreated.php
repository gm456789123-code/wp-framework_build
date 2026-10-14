<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\ValueObject\PostId;
use DateTimeImmutable;

final readonly class PageCreated
{
    public function __construct(
        public PostId $pageId,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
