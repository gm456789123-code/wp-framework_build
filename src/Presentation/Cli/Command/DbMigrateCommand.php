<?php

declare(strict_types=1);

namespace App\Presentation\Cli\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Override;

#[AsCommand(name: 'db:migrate', description: 'Run versioned database migrations (Phinx).')]
final class DbMigrateCommand extends Command
{
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $process = new Process([
            'vendor/bin/phinx', 'migrate',
            '--configuration', 'phinx.php',
        ]);
        $process->setTimeout(120);
        $process->run(function (string $type, string $buffer) use ($output): void {
            $output->write($buffer);
        });

        return $process->getExitCode() ?? Command::FAILURE;
    }
}
