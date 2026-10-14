<?php

declare(strict_types=1);

namespace App\Application\UseCase\Page;

use App\Domain\ValueObject\Locale;

final readonly class CreatePageInput
{
    public function __construct(
        public string $title,
        public string $content,
        public Locale $locale,
    ) {
    }
}
