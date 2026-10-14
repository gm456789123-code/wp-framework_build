<?php

declare(strict_types=1);

namespace App\Application\UseCase\Page;

use App\Domain\Model\Page;

final readonly class GetPageOutput
{
    public function __construct(
        public ?Page $page,
    ) {
    }
}
