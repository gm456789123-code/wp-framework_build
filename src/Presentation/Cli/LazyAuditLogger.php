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
        // No hardcoded fallback: a literal default here would be a known
        // key readable in this public codebase, letting anyone recompute
        // a valid-looking HMAC for a tampered audit entry. A real APP_KEY
        // exists once the site is installed (wp-config.php); run this
        // after install, not before.
        $key = Env::appKey();

        return $this->inner ??= new MerkleAuditLogger(WpdbConnection::get(), $key);
    }
}
