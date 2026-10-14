<?php

declare(strict_types=1);

namespace App\Application\UseCase\Page;

use App\Domain\Repository\PageRepositoryInterface;

final readonly class GetPage
{
    public function __construct(
        private PageRepositoryInterface $pages,
    ) {
    }

    public function handle(GetPageInput $input): GetPageOutput
    {
        return new GetPageOutput($this->pages->find($input->id));
    }
}
