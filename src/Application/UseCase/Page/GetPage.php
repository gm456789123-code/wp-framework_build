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
        $page = $input->forPreview
            ? $this->pages->findForPreview($input->id)
            : $this->pages->find($input->id);

        return new GetPageOutput($page);
    }
}
