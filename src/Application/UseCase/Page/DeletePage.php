<?php

declare(strict_types=1);

namespace App\Application\UseCase\Page;

use App\Domain\Event\DomainEventDispatcherInterface;
use App\Domain\Event\PageDeleted;
use App\Domain\Repository\PageRepositoryInterface;
use DateTimeImmutable;

final readonly class DeletePage
{
    public function __construct(
        private PageRepositoryInterface $pages,
        private DomainEventDispatcherInterface $events,
    ) {
    }

    public function handle(DeletePageInput $input): DeletePageOutput
    {
        $wasDeleted = $this->pages->softDelete($input->pageId, $input->deletedBy);

        if ($wasDeleted) {
            $this->events->dispatch(new PageDeleted($input->pageId, $input->deletedBy, new DateTimeImmutable()));
        }

        return new DeletePageOutput($wasDeleted);
    }
}
