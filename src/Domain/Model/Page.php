<?php

declare(strict_types=1);

namespace App\Domain\Model;

use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\PageStatus;
use App\Domain\ValueObject\PostId;
use DateTimeImmutable;

final readonly class Page
{
    public function __construct(
        public PostId $id,
        public string $title,
        public string $content,
        public PageStatus $status,
        public Locale $locale,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
