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
use Throwable;

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
            "SELECT ID, post_title, post_name, post_content, post_excerpt, post_status, locale, post_date, post_modified
             FROM wp_posts
             WHERE ID = :id
               AND post_status = 'publish'
               AND post_type IN ('page','post')
               AND deleted_at IS NULL",
        );
        $statement->execute(['id' => $id->value]);

        /** @var array{ID: int, post_title: string, post_name: string, post_content: string, post_excerpt: string, post_status: string, locale: string, post_date: string, post_modified: string}|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->hydratePage($row, PageStatus::from($row['post_status']));
    }

    #[Override]
    public function findForPreview(PostId $id): ?Page
    {
        $statement = $this->connection->prepare(
            "SELECT ID, post_title, post_name, post_content, post_excerpt, post_status, locale, post_date, post_modified
             FROM wp_posts
             WHERE ID = :id
               AND post_type IN ('page','post')
               AND post_status IN ('publish','draft','pending','future','private')
               AND deleted_at IS NULL",
        );
        $statement->execute(['id' => $id->value]);

        /** @var array{ID: int, post_title: string, post_name: string, post_content: string, post_excerpt: string, post_status: string, locale: string, post_date: string, post_modified: string}|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $status = PageStatus::tryFrom($row['post_status']) ?? PageStatus::Draft;

        return $this->hydratePage($row, $status);
    }

    #[Override]
    public function findLatestPublishedId(?Locale $locale = null): ?PostId
    {
        $sql = "SELECT ID FROM wp_posts
                WHERE post_status = 'publish'
                  AND post_type IN ('page','post')
                  AND deleted_at IS NULL";
        $params = [];
        if ($locale !== null) {
            $sql .= ' AND locale = :locale';
            $params['locale'] = $locale->value;
        }
        $sql .= ' ORDER BY post_date DESC LIMIT 1';

        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        /** @var array{ID: int}|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row !== false) {
            return new PostId($row['ID']);
        }

        // Fallback: If no post in requested locale, return latest published post of any locale
        if ($locale !== null) {
            $fallbackSql = "SELECT ID FROM wp_posts
                            WHERE post_status = 'publish'
                              AND post_type IN ('page','post')
                              AND deleted_at IS NULL
                            ORDER BY post_date DESC LIMIT 1";
            $fbStmt = $this->connection->query($fallbackSql);
            if ($fbStmt !== false) {
                /** @var array{ID: int}|false $fbRow */
                $fbRow = $fbStmt->fetch(PDO::FETCH_ASSOC);
                if ($fbRow !== false) {
                    return new PostId($fbRow['ID']);
                }
            }
        }

        return null;
    }

    #[Override]
    public function findFrontPageId(?Locale $locale = null): ?PostId
    {
        try {
            $optKey = $locale !== null ? "page_on_front_{$locale->value}" : 'page_on_front';
            $statement = $this->connection->query(
                "SELECT option_name, option_value FROM wp_options
                 WHERE option_name IN ('show_on_front', 'page_on_front', '{$optKey}')",
            );

            /** @var array<string, string> $options */
            $options = [];
            if ($statement !== false) {
                /** @var list<array{option_name: string, option_value: string}> $rows */
                $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $options[$row['option_name']] = $row['option_value'];
                }
            }

            $targetOpt = $options[$optKey] ?? $options['page_on_front'] ?? null;
            if (($options['show_on_front'] ?? '') === 'page' && $targetOpt !== null && $targetOpt !== '') {
                $pageId = (int) $targetOpt;
                if ($pageId > 0) {
                    $page = $this->find(new PostId($pageId));
                    if ($page !== null && ($locale === null || $page->locale === $locale)) {
                        return $page->id;
                    }
                }
            }
        } catch (Throwable) {
            // Fallback if wp_options is missing
        }

        return $this->findLatestPublishedId($locale);
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

    #[Override]
    public function findBySlug(string $slug, ?Locale $locale = null): ?Page
    {
        $sql = "SELECT ID, post_title, post_name, post_content, post_excerpt, post_status, locale, post_date, post_modified
                FROM wp_posts
                WHERE post_name = :slug
                  AND post_status = 'publish'
                  AND post_type IN ('page','post')
                  AND deleted_at IS NULL";
        $params = ['slug' => $slug];
        if ($locale !== null) {
            $sql .= ' AND locale = :locale';
            $params['locale'] = $locale->value;
        }
        $sql .= ' LIMIT 1';

        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        /** @var array{ID: int, post_title: string, post_name: string, post_content: string, post_excerpt: string, post_status: string, locale: string, post_date: string, post_modified: string}|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->hydratePage($row, PageStatus::from($row['post_status']));
    }

    /**
     * @param array{ID: int, post_title: string, post_name: string, post_content: string, post_excerpt: string, post_status: string, locale: string, post_date: string, post_modified: string} $row
     */
    private function hydratePage(array $row, PageStatus $status): Page
    {
        $modifiedAt = $row['post_modified'] !== '' && $row['post_modified'] !== '0000-00-00 00:00:00'
            ? new DateTimeImmutable($row['post_modified'])
            : new DateTimeImmutable($row['post_date']);

        $seoOverrides = $this->fetchSeoPluginOverrides($row['ID']);

        return new Page(
            id: new PostId($row['ID']),
            title: $row['post_title'],
            content: $row['post_content'],
            status: $status,
            locale: Locale::from($row['locale']),
            createdAt: new DateTimeImmutable($row['post_date']),
            slug: $row['post_name'],
            excerpt: $row['post_excerpt'],
            modifiedAt: $modifiedAt,
            seoTitleOverride: $seoOverrides['title'],
            seoDescriptionOverride: $seoOverrides['description'],
            seoOgImageOverride: $seoOverrides['ogImage'],
        );
    }

    /**
     * Reads per-page SEO fields a site owner set through Yoast SEO's own
     * wp-admin meta box, stored as normal postmeta like any other WP
     * plugin -- RankMath/AIOSEO support can follow the same shape later,
     * trying their key names in the same IN(...) list.
     *
     * @return array{title: ?string, description: ?string, ogImage: ?string}
     */
    private function fetchSeoPluginOverrides(int $postId): array
    {
        try {
            $statement = $this->connection->prepare(
                "SELECT meta_key, meta_value FROM wp_postmeta
                 WHERE post_id = :post_id
                   AND meta_key IN ('_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_opengraph-image')",
            );
            $statement->execute(['post_id' => $postId]);

            /** @var list<array{meta_key: string, meta_value: string}> $rows */
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

            $values = [];
            foreach ($rows as $metaRow) {
                $values[$metaRow['meta_key']] = $metaRow['meta_value'];
            }

            return [
                'title' => $values['_yoast_wpseo_title'] ?? null,
                'description' => $values['_yoast_wpseo_metadesc'] ?? null,
                'ogImage' => $values['_yoast_wpseo_opengraph-image'] ?? null,
            ];
        } catch (Throwable) {
            // wp_postmeta missing or Yoast never installed -- not an error,
            // just nothing to override.
            return ['title' => null, 'description' => null, 'ogImage' => null];
        }
    }

    private static function slugify(string $title): string
    {
        $slug = mb_strtolower(trim($title), 'UTF-8');
        $slug = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'page-' . bin2hex(random_bytes(2));
    }
}
