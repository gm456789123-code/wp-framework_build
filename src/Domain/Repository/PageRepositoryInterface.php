<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Model\Page;
use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\PageStatus;
use App\Domain\ValueObject\PostId;

interface PageRepositoryInterface
{
    public function create(string $title, string $content, PageStatus $status, Locale $locale): PostId;

    public function find(PostId $id): ?Page;

    /**
     * Most recently published, non-deleted page -- used for the site
     * homepage when no specific id is requested (mirrors WordPress's own
     * "show_on_front=posts" behaviour without needing WordPress loaded).
     */
    public function findLatestPublishedId(): ?PostId;

    /**
     * @return bool true if a row was actually soft-deleted, false if the
     *              page didn't exist or was already deleted.
     */
    public function softDelete(PostId $id, PostId $deletedBy): bool;
}
