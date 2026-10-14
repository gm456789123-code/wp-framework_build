<?php

declare(strict_types=1);

namespace App\Presentation\Cli\Command;

use App\Infrastructure\Env;
use App\Infrastructure\Installer\SmartBootstrapper;
use App\Infrastructure\Wordpress\WpdbConnection;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Installing over HTTP with no auth check means the first unauthenticated
 * visitor, not the site owner, wins control of the admin account -- so this
 * is CLI-only, deliberately not reachable from a web request.
 */
#[AsCommand(name: 'wp:install', description: 'Installs WordPress core, database, and the Sovereign theme if not already installed.')]
final class WpInstallCommand extends Command
{
    #[Override]
    protected function configure(): void
    {
        $this
            ->addOption('admin-user', null, InputOption::VALUE_REQUIRED, 'Admin username', 'admin')
            ->addOption('admin-password', null, InputOption::VALUE_REQUIRED, 'Admin password (random if omitted)')
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'Admin email', 'admin@example.com');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pdo = null;
        try {
            $pdo = WpdbConnection::get();
        } catch (Throwable $e) {
            $output->writeln("<error>Could not connect to the database: {$e->getMessage()}</error>");

            return Command::FAILURE;
        }

        $bootstrapper = new SmartBootstrapper($pdo);
        $log = function (string $message) use ($output): void {
            $output->writeln($message);
        };

        $env = $bootstrapper->detectEnvironment();

        // Fail safe, not fail open: if we can't actually verify whether
        // WordPress is installed, that is not the same thing as "it isn't"
        // -- proceeding anyway would silently re-run wp_install() against
        // an already-installed site's database because of a transient
        // connection hiccup, resetting its real admin account.
        if (!$env['dbConnected']) {
            $output->writeln('<error>Could not verify installation status (database check failed). Aborting rather than risk reinstalling over an existing site.</error>');

            return Command::FAILURE;
        }

        if ($env['tablesExist']) {
            $output->writeln('<comment>WordPress is already installed, skipping.</comment>');

            return Command::SUCCESS;
        }

        if (!$env['coreFilesExist']) {
            if (!$bootstrapper->downloadCore($env['targetDir'], $log)) {
                return Command::FAILURE;
            }
        } else {
            $log('[INFO] Core files verified.');
        }

        if (!$env['configExists']) {
            $dbConfig = [
                'host' => Env::string('DB_HOST', 'mysql'),
                'db' => Env::string('DB_DATABASE', 'wordpress'),
                'user' => Env::string('DB_USERNAME', 'wordpress'),
                'pass' => Env::string('DB_PASSWORD', 'wordpress'),
            ];
            if (!$bootstrapper->generateWpConfig($env['targetDir'], $dbConfig, $log)) {
                return Command::FAILURE;
            }
        }

        /** @var string $adminUser */
        $adminUser = $input->getOption('admin-user');
        /** @var string|null $adminPassword */
        $adminPassword = $input->getOption('admin-password');
        /** @var string $adminEmail */
        $adminEmail = $input->getOption('admin-email');

        $bootstrapper->provisionDatabase($env['targetDir'], $log, $adminUser, $adminPassword, $adminEmail);
        $bootstrapper->activateSovereignTheme($log);

        $output->writeln('<info>WordPress installed.</info>');

        return Command::SUCCESS;
    }
}
