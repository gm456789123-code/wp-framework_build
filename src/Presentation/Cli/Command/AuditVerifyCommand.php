<?php

declare(strict_types=1);

namespace App\Presentation\Cli\Command;

use App\Domain\Repository\AuditLoggerInterface;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'audit:verify', description: 'Validates cryptographic Merkle hash chain integrity of the audit log.')]
final class AuditVerifyCommand extends Command
{
    public function __construct(
        private AuditLoggerInterface $auditLogger,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Sovereign Engine — Cryptographic Audit Trail Verification');

        $result = $this->auditLogger->verifyChain();

        if ($result['valid']) {
            $io->success(sprintf(
                'Hash chain integrity verified! %d records checked. 0 tamper breaches detected.',
                $result['totalRows']
            ));

            return Command::SUCCESS;
        }

        $io->error(sprintf(
            'SECURITY ALERT: Cryptographic chain mismatch! Tampering detected at row ID: %d (after checking %d records).',
            $result['brokenAtId'] ?? 0,
            $result['totalRows']
        ));

        return Command::FAILURE;
    }
}
