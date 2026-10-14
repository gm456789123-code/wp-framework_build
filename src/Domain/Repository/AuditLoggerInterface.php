<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Model\AuditLogEntry;
use App\Domain\ValueObject\UserId;

interface AuditLoggerInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function log(UserId $userId, string $action, string $objectId, array $payload = []): AuditLogEntry;

    /**
     * @return array{valid: bool, totalRows: int, brokenAtId: ?int}
     */
    public function verifyChain(): array;
}
