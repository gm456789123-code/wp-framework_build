<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Model\Page;
use App\Domain\ValueObject\Locale;

interface SearchRepositoryInterface
{
    /**
     * @return list<Page>
     */
    public function search(string $term, ?Locale $locale = null, int $limit = 20): array;
}
