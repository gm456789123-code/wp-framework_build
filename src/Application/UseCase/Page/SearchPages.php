<?php

declare(strict_types=1);

namespace App\Application\UseCase\Page;

use App\Domain\Repository\SearchRepositoryInterface;

final readonly class SearchPages
{
    public function __construct(
        private SearchRepositoryInterface $searchRepository,
    ) {
    }

    public function handle(SearchPagesInput $input): SearchPagesOutput
    {
        $pages = $this->searchRepository->search(
            $input->query,
            $input->locale,
            $input->limit,
        );

        return new SearchPagesOutput($pages);
    }
}
