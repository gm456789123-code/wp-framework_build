<?php

declare(strict_types=1);

namespace App\Presentation\Cli\Command;

use App\Application\UseCase\Page\GetPage;
use App\Application\UseCase\Page\GetPageInput;
use App\Domain\Repository\PageRepositoryInterface;
use App\Presentation\Cli\Support\PostIdArgument;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'page:show', description: 'Show a page by id.')]
final class PageShowCommand extends Command
{
    public function __construct(
        private readonly PageRepositoryInterface $pages,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Page id');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $idArgument */
        $idArgument = $input->getArgument('id');
        $id = PostIdArgument::parse($idArgument, 'id');

        $useCase = new GetPage($this->pages);

        $result = $useCase->handle(new GetPageInput($id));

        if ($result->page === null) {
            $output->writeln('<comment>Not found.</comment>');

            return Command::FAILURE;
        }

        $output->writeln("<info>#{$result->page->id->toString()}</info> {$result->page->title}");
        $output->writeln("Status: {$result->page->status->value}  Locale: {$result->page->locale->value}");
        $output->writeln($result->page->content);

        return Command::SUCCESS;
    }
}
