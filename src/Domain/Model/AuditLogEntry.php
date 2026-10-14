<?php

declare(strict_types=1);

namespace App\Domain\Model;

use App\Domain\ValueObject\UserId;
use DateTimeImmutable;

final readonly class AuditLogEntry
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public ?int $id,
        public string $prevHash,
        public string $currentHash,
        public UserId $userId,
        public string $action,
        public string $objectId,
        public array $payload,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
