<?php

declare(strict_types=1);

namespace App\Presentation\Cli;

use App\Domain\Model\Page;
use App\Domain\Repository\PageRepositoryInterface;
use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\PageStatus;
use App\Domain\ValueObject\PostId;
use App\Infrastructure\Wordpress\WpdbConnection;
use App\Infrastructure\Wordpress\WpPageRepository;
use Override;

/**
 * Defers connecting to the database until a command actually calls one of
 * these methods. Without this, registering every `page:*` command at
 * bin/console's composition root would open a database connection on every
 * single invocation — including `make:page` or `--help`, which have nothing
 * to do with the database — and crash outright if it's unreachable.
 */
final class LazyPageRepository implements PageRepositoryInterface
{
    private ?PageRepositoryInterface $inner = null;

    #[Override]
    public function create(string $title, string $content, PageStatus $status, Locale $locale): PostId
    {
        return $this->inner()->create($title, $content, $status, $locale);
    }

    #[Override]
    public function find(PostId $id): ?Page
    {
        return $this->inner()->find($id);
    }

    #[Override]
    public function findBySlug(string $slug, ?Locale $locale = null): ?Page
    {
        return $this->inner()->findBySlug($slug, $locale);
    }

    #[Override]
    public function softDelete(PostId $id, PostId $deletedBy): bool
    {
        return $this->inner()->softDelete($id, $deletedBy);
    }

    #[Override]
    public function findLatestPublishedId(?Locale $locale = null): ?PostId
    {
        return $this->inner()->findLatestPublishedId($locale);
    }

    #[Override]
    public function findFrontPageId(?Locale $locale = null): ?PostId
    {
        return $this->inner()->findFrontPageId($locale);
    }

    #[Override]
    public function findForPreview(PostId $id): ?Page
    {
        return $this->inner()->findForPreview($id);
    }

    private function inner(): PageRepositoryInterface
    {
        return $this->inner ??= new WpPageRepository(WpdbConnection::get());
    }
}
