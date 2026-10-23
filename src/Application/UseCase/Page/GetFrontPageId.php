<?php

declare(strict_types=1);

namespace App\Application\UseCase\Page;

use App\Domain\Repository\PageRepositoryInterface;

final readonly class GetFrontPageId
{
    public function __construct(
        private PageRepositoryInterface $pages,
    ) {
    }

    public function handle(): GetLatestPublishedPageIdOutput
    {
        return new GetLatestPublishedPageIdOutput($this->pages->findFrontPageId());
    }
}
