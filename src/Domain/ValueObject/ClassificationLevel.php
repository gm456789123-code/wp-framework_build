<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

enum ClassificationLevel: string
{
    case Public = 'public';
    case Internal = 'internal';
    case Restricted = 'restricted';
    case Confidential = 'confidential';

    public function isSensitive(): bool
    {
        return $this === self::Restricted || $this === self::Confidential;
    }
}
