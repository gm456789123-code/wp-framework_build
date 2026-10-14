<?php

declare(strict_types=1);

namespace App\Presentation\Cli\Command;

use App\Application\UseCase\Page\DeletePage;
use App\Application\UseCase\Page\DeletePageInput;
use App\Domain\Event\DomainEventDispatcherInterface;
use App\Domain\Repository\PageRepositoryInterface;
use App\Presentation\Cli\Support\PostIdArgument;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'page:delete', description: 'Soft-delete a page by id.')]
final class PageDeleteCommand extends Command
{
    public function __construct(
        private readonly PageRepositoryInterface $pages,
        private readonly DomainEventDispatcherInterface $events,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Page id')
            ->addOption('deleted-by', null, InputOption::VALUE_REQUIRED, 'User id performing the delete', '1');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $idArgument */
        $idArgument = $input->getArgument('id');
        /** @var string $deletedByArgument */
        $deletedByArgument = $input->getOption('deleted-by');

        $id = PostIdArgument::parse($idArgument, 'id');
        $deletedBy = PostIdArgument::parse($deletedByArgument, 'deleted-by');

        $useCase = new DeletePage($this->pages, $this->events);

        $result = $useCase->handle(new DeletePageInput($id, $deletedBy));

        if (!$result->wasDeleted) {
            $output->writeln("<comment>Page #{$idArgument} not found (or already deleted).</comment>");

            return Command::FAILURE;
        }

        $output->writeln("<info>Deleted page #{$idArgument}</info>");

        return Command::SUCCESS;
    }
}
