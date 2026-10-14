<?php

declare(strict_types=1);

namespace App\Application\UseCase\Page;

use App\Domain\ValueObject\PostId;

final readonly class GetPageInput
{
    public function __construct(
        public PostId $id,
    ) {
    }
}
