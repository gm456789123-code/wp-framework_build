<?php

declare(strict_types=1);

namespace App\Application\UseCase\Page;

use App\Domain\Model\Page;

final readonly class SearchPagesOutput
{
    /**
     * @param list<Page> $pages
     */
    public function __construct(
        public array $pages,
    ) {
    }
}
