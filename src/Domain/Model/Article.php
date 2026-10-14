<?php

declare(strict_types=1);

namespace App\Domain\Model;

use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\PostId;
use App\Domain\ValueObject\UuidV7;

final class Article
{
    use TranslatableTrait;

    public function __construct(
        public PostId $id,
        public string $title,
        Locale $locale,
        ?UuidV7 $translationGroupId = null,
    ) {
        $this->locale = $locale;
        $this->translationGroupId = $translationGroupId;
    }
}
