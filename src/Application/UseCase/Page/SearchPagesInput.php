<?php

declare(strict_types=1);

namespace App\Application\UseCase\Page;

use App\Domain\ValueObject\Locale;

final readonly class SearchPagesInput
{
    public function __construct(
        public string $query,
        public ?Locale $locale = null,
        public int $limit = 20,
    ) {
    }
}
