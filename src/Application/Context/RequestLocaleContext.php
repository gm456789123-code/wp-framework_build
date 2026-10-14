<?php

declare(strict_types=1);

namespace App\Application\Context;

use App\Domain\ValueObject\Locale;

final class RequestLocaleContext
{
    private Locale $currentLocale;

    public function __construct(Locale $defaultLocale = Locale::Thai)
    {
        $this->currentLocale = $defaultLocale;
    }

    public function getLocale(): Locale
    {
        return $this->currentLocale;
    }

    public function setLocale(Locale $locale): void
    {
        $this->currentLocale = $locale;
    }
}
