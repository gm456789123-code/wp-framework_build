<?php

declare(strict_types=1);

namespace App\Application\UseCase\Page;

use App\Domain\Event\DomainEventDispatcherInterface;
use App\Domain\Event\PageCreated;
use App\Domain\Repository\PageRepositoryInterface;
use App\Domain\ValueObject\PageStatus;
use DateTimeImmutable;

final readonly class CreatePage
{
    public function __construct(
        private PageRepositoryInterface $pages,
        private DomainEventDispatcherInterface $events,
    ) {
    }

    public function handle(CreatePageInput $input): CreatePageOutput
    {
        $id = $this->pages->create(
            $input->title,
            $input->content,
            PageStatus::Draft,
            $input->locale,
        );

        $this->events->dispatch(new PageCreated($id, new DateTimeImmutable()));

        return new CreatePageOutput($id);
    }
}
