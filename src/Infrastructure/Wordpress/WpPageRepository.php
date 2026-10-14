<?php

declare(strict_types=1);

namespace App\Infrastructure\Wordpress;

use App\Domain\Model\Page;
use App\Domain\Repository\PageRepositoryInterface;
use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\PageStatus;
use App\Domain\ValueObject\PostId;
use DateTimeImmutable;
use Override;
use PDO;

final readonly class WpPageRepository implements PageRepositoryInterface
{
    public function __construct(
        private PDO $connection,
    ) {
    }

    #[Override]
    public function create(string $title, string $content, PageStatus $status, Locale $locale): PostId
    {
        $now = new DateTimeImmutable();

        $statement = $this->connection->prepare(
            'INSERT INTO wp_posts
                (post_title, post_content, post_status, post_type, post_author,
                 post_date, post_date_gmt, post_modified, post_modified_gmt,
                 post_name, locale)
             VALUES
                (:title, :content, :status, \'page\', 1,
                 :date, :date, :date, :date,
                 :name, :locale)',
        );

        $statement->execute([
            'title' => $title,
            'content' => $content,
            'status' => $status->value,
            'date' => $now->format('Y-m-d H:i:s'),
            'name' => self::slugify($title),
            'locale' => $locale->value,
        ]);

        return new PostId((int) $this->connection->lastInsertId());
    }

    #[Override]
    public function find(PostId $id): ?Page
    {
        $statement = $this->connection->prepare(
            'SELECT ID, post_title, post_content, post_status, locale, post_date
             FROM wp_posts
             WHERE ID = :id AND deleted_at IS NULL',
        );
        $statement->execute(['id' => $id->value]);

        /** @var array{ID: int, post_title: string, post_content: string, post_status: string, locale: string, post_date: string}|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return new Page(
            new PostId($row['ID']),
            $row['post_title'],
            $row['post_content'],
            PageStatus::from($row['post_status']),
            Locale::from($row['locale']),
            new DateTimeImmutable($row['post_date']),
        );
    }

    #[Override]
    public function findLatestPublishedId(): ?PostId
    {
        $statement = $this->connection->query(
            'SELECT ID FROM wp_posts
             WHERE post_status = \'publish\' AND deleted_at IS NULL
             ORDER BY post_date DESC
             LIMIT 1',
        );

        /** @var array{ID: int}|false $row */
        $row = $statement !== false ? $statement->fetch(PDO::FETCH_ASSOC) : false;

        return $row !== false ? new PostId($row['ID']) : null;
    }

    #[Override]
    public function softDelete(PostId $id, PostId $deletedBy): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE wp_posts
             SET deleted_at = :now, deleted_by = :deletedBy
             WHERE ID = :id AND deleted_at IS NULL',
        );
        $statement->execute([
            'now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'deletedBy' => $deletedBy->value,
            'id' => $id->value,
        ]);

        return $statement->rowCount() > 0;
    }

    private static function slugify(string $title): string
    {
        $slug = strtolower(trim($title));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }
}
