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
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'make:block', description: 'Scaffolds a native Gutenberg block Latte template and bindings.')]
final class MakeBlockCommand extends Command
{
    private const string PROJECT_ROOT = __DIR__ . '/../../../..';

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Block name in kebab-case or PascalCase (e.g. callout or HeroBanner)');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $rawName */
        $rawName = $input->getArgument('name');
        $slug = strtolower(trim((string) preg_replace('/([a-z])([A-Z])/', '$1-$2', $rawName)));

        $templateFile = self::PROJECT_ROOT . "/templates/blocks/{$slug}.latte";

        if (file_exists($templateFile)) {
            $io->warning(sprintf('Template already exists: templates/blocks/%s.latte', $slug));

            return Command::SUCCESS;
        }

        $dir = dirname($templateFile);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create directory: {$dir}");
        }

        $templateContent = <<<LATTE
{* Custom Gutenberg block: core/{$slug} or theme/{$slug} *}
<div class="my-6 p-6 rounded-xl bg-slate-50 border border-slate-200 shadow-sm">
    <div class="prose max-w-none text-slate-800">
        {\$innerHTML|noescape}
    </div>
</div>
LATTE;

        file_put_contents($templateFile, $templateContent);
        $io->success(sprintf('Scaffolded block template: templates/blocks/%s.latte', $slug));

        return Command::SUCCESS;
    }
}
