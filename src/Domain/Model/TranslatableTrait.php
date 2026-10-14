<?php

declare(strict_types=1);

namespace App\Domain\Model;

use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\UuidV7;

trait TranslatableTrait
{
    public Locale $locale;
    public ?UuidV7 $translationGroupId;

    public function getLocale(): Locale
    {
        return $this->locale;
    }

    public function getTranslationGroupId(): ?UuidV7
    {
        return $this->translationGroupId;
    }

    public function isTranslatedTo(Locale $locale): bool
    {
        return $this->locale === $locale;
    }
}
