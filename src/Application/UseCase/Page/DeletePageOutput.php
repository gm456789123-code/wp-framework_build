<?php

declare(strict_types=1);

namespace App\Application\UseCase\Page;

final readonly class DeletePageOutput
{
    public function __construct(
        public bool $wasDeleted,
    ) {
    }
}
