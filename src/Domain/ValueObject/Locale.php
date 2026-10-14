<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

enum Locale: string
{
    case Thai = 'th';
    case English = 'en';
}
