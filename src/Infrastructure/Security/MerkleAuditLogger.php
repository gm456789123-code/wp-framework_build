<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Model\AuditLogEntry;
use App\Domain\Repository\AuditLoggerInterface;
use App\Domain\ValueObject\UserId;
use DateTimeImmutable;
use Override;
use PDO;
use RuntimeException;

final readonly class MerkleAuditLogger implements AuditLoggerInterface
{
    private const string GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(
        private PDO $connection,
        private string $secretKey,
    ) {
    }

    #[Override]
    public function log(UserId $userId, string $action, string $objectId, array $payload = []): AuditLogEntry
    {
        // The chain is read-then-append: two concurrent writers reading the
        // same last row and both appending from it would fork the chain, so
        // the read and the insert must be serialized with a DB-wide lock
        // rather than relying on in-process locking (multiple PHP-FPM
        // workers/processes share nothing in-process). GET_LOCK is MySQL-only
        // (the unit test exercises this class against an in-memory SQLite
        // connection, which has no concurrent writers to race in the first
        // place), so it's skipped on any other driver.
        $isMysql = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

        if ($isMysql) {
            $lockStatement = $this->connection->query("SELECT GET_LOCK('wp_audit_log_chain', 10)");
            $acquired = $lockStatement !== false && (int) $lockStatement->fetchColumn() === 1;

            if (!$acquired) {
                throw new RuntimeException('Could not acquire the audit log chain lock within 10 seconds.');
            }
        }

        try {
            $statement = $this->connection->query(
                'SELECT current_hash FROM wp_audit_log ORDER BY id DESC LIMIT 1'
            );

            if ($statement === false) {
                $prevHash = self::GENESIS_HASH;
            } else {
                /** @var array{current_hash: string}|false $lastRow */
                $lastRow = $statement->fetch(PDO::FETCH_ASSOC);
                $prevHash = $lastRow !== false ? $lastRow['current_hash'] : self::GENESIS_HASH;
            }

            $now = new DateTimeImmutable();
            $createdAt = $now->format('Y-m-d H:i:s');
            $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $currentHash = $this->calculateHash($prevHash, $userId->value, $action, $objectId, $payloadJson, $createdAt);

            $insert = $this->connection->prepare(
                'INSERT INTO wp_audit_log
                    (prev_hash, current_hash, user_id, action, object_id, payload_json, created_at)
                 VALUES
                    (:prev_hash, :current_hash, :user_id, :action, :object_id, :payload_json, :created_at)'
            );

            $insert->execute([
                'prev_hash' => $prevHash,
                'current_hash' => $currentHash,
                'user_id' => $userId->value,
                'action' => $action,
                'object_id' => $objectId,
                'payload_json' => $payloadJson,
                'created_at' => $createdAt,
            ]);

            $id = (int) $this->connection->lastInsertId();

            return new AuditLogEntry(
                $id,
                $prevHash,
                $currentHash,
                $userId,
                $action,
                $objectId,
                $payload,
                $now,
            );
        } finally {
            if ($isMysql) {
                $this->connection->query("SELECT RELEASE_LOCK('wp_audit_log_chain')");
            }
        }
    }

    #[Override]
    public function verifyChain(): array
    {
        $statement = $this->connection->query(
            'SELECT id, prev_hash, current_hash, user_id, action, object_id, payload_json, created_at
             FROM wp_audit_log
             ORDER BY id ASC'
        );

        if ($statement === false) {
            return ['valid' => true, 'totalRows' => 0, 'brokenAtId' => null];
        }

        /** @var list<array{id: int|numeric-string, prev_hash: string, current_hash: string, user_id: int|numeric-string, action: string, object_id: string, payload_json: string, created_at: string}> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $expectedPrevHash = self::GENESIS_HASH;
        $totalRows = 0;

        foreach ($rows as $row) {
            $totalRows++;
            $rowId = (int) $row['id'];
            $userId = (int) $row['user_id'];

            // 1. Check prev_hash matches chain
            if ($row['prev_hash'] !== $expectedPrevHash) {
                return ['valid' => false, 'totalRows' => $totalRows, 'brokenAtId' => $rowId];
            }

            // 2. Recalculate HMAC hash
            $computedHash = $this->calculateHash(
                $row['prev_hash'],
                $userId,
                $row['action'],
                $row['object_id'],
                $row['payload_json'],
                $row['created_at'],
            );

            if (!hash_equals($computedHash, $row['current_hash'])) {
                return ['valid' => false, 'totalRows' => $totalRows, 'brokenAtId' => $rowId];
            }

            $expectedPrevHash = $row['current_hash'];
        }

        return ['valid' => true, 'totalRows' => $totalRows, 'brokenAtId' => null];
    }

    private function calculateHash(
        string $prevHash,
        int $userId,
        string $action,
        string $objectId,
        string $payloadJson,
        string $createdAt,
    ): string {
        $data = $prevHash . (string) $userId . $action . $objectId . $payloadJson . $createdAt;

        return hash_hmac('sha256', $data, $this->secretKey);
    }
}
