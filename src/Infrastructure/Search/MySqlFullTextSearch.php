<?php

declare(strict_types=1);

namespace App\Infrastructure\Search;

use App\Domain\Model\Page;
use App\Domain\Repository\SearchRepositoryInterface;
use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\PageStatus;
use App\Domain\ValueObject\PostId;
use DateTimeImmutable;
use Override;
use PDO;

final readonly class MySqlFullTextSearch implements SearchRepositoryInterface
{
    public function __construct(
        private PDO $connection,
    ) {
    }

    #[Override]
    public function search(string $term, ?Locale $locale = null, int $limit = 20): array
    {
        $cleanTerm = trim($term);
        if ($cleanTerm === '') {
            return [];
        }

        $sql = "SELECT ID, post_title, post_content, post_status, locale, post_date
                FROM wp_posts
                WHERE MATCH(post_title, post_content) AGAINST(:term IN BOOLEAN MODE)
                  AND post_status = 'publish'
                  AND post_type IN ('page','post')
                  AND deleted_at IS NULL";

        $params = [
            'term' => $cleanTerm . '*',
        ];

        if ($locale !== null) {
            $sql .= ' AND locale = :locale';
            $params['locale'] = $locale->value;
        }

        $sql .= ' ORDER BY ID DESC LIMIT ' . max(1, $limit);

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);

        /** @var list<array{ID: int|numeric-string, post_title: string, post_content: string, post_status: string, locale: string, post_date: string}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $row) {
            $results[] = new Page(
                new PostId((int) $row['ID']),
                $row['post_title'],
                $row['post_content'],
                PageStatus::from($row['post_status']),
                Locale::from($row['locale']),
                new DateTimeImmutable($row['post_date']),
            );
        }

        return $results;
    }
}
