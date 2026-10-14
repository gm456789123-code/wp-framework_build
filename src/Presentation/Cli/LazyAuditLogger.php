<?php

declare(strict_types=1);

namespace App\Presentation\Cli;

use App\Domain\Model\AuditLogEntry;
use App\Domain\Repository\AuditLoggerInterface;
use App\Domain\ValueObject\UserId;
use App\Infrastructure\Env;
use App\Infrastructure\Security\MerkleAuditLogger;
use App\Infrastructure\Wordpress\WpdbConnection;
use Override;

final class LazyAuditLogger implements AuditLoggerInterface
{
    private ?AuditLoggerInterface $inner = null;

    #[Override]
    public function log(UserId $userId, string $action, string $objectId, array $payload = []): AuditLogEntry
    {
        return $this->inner()->log($userId, $action, $objectId, $payload);
    }

    #[Override]
    public function verifyChain(): array
    {
        return $this->inner()->verifyChain();
    }

    private function inner(): AuditLoggerInterface
    {
        $key = Env::string('APP_KEY', 'default-32-byte-secret-key-1234');

        return $this->inner ??= new MerkleAuditLogger(WpdbConnection::get(), $key);
    }
}
