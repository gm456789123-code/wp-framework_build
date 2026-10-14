<?php

declare(strict_types=1);

namespace App\Presentation\Cli\Command;

use Override;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'make:page', description: 'Scaffold an Entity, DTO, Repository, Latte view, and route stub for a new page type.')]
final class MakePageCommand extends Command
{
    private const string STUBS_DIR = __DIR__ . '/../stubs';
    private const string PROJECT_ROOT = __DIR__ . '/../../../..';

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'PascalCase name of the page entity, e.g. Event');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $name */
        $name = $input->getArgument('name');
        $lower = strtolower($name);

        $targets = [
            self::STUBS_DIR . '/Entity.stub' => "src/Domain/Model/{$name}.php",
            self::STUBS_DIR . '/RepositoryInterface.stub' => "src/Domain/Repository/{$name}RepositoryInterface.php",
            self::STUBS_DIR . '/RepositoryImpl.stub' => "src/Infrastructure/Wordpress/Wp{$name}Repository.php",
            self::STUBS_DIR . '/Dto.stub' => "src/Application/UseCase/{$name}/{$name}Input.php",
            self::STUBS_DIR . '/View.latte.stub' => "src/Presentation/View/{$lower}.latte",
        ];

        foreach ($targets as $stubPath => $relativeTarget) {
            $targetPath = self::PROJECT_ROOT . '/' . $relativeTarget;

            if (file_exists($targetPath)) {
                $output->writeln("<comment>Skipped (already exists):</comment> {$relativeTarget}");
                continue;
            }

            $stub = file_get_contents($stubPath);
            if ($stub === false) {
                throw new RuntimeException("Missing stub: {$stubPath}");
            }

            $contents = str_replace(['{{Name}}', '{{name}}'], [$name, $lower], $stub);

            $directory = dirname($targetPath);
            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException("Could not create directory: {$directory}");
            }

            file_put_contents($targetPath, $contents);
            $output->writeln("<info>Created:</info> {$relativeTarget}");
        }

        return Command::SUCCESS;
    }
}
