<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

enum PageStatus: string
{
    case Draft = 'draft';
    case Published = 'publish';
}
