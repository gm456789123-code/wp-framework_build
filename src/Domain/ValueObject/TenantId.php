<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

use InvalidArgumentException;

final readonly class TenantId
{
    public string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);
        if ($trimmed === '' || preg_match('/^[a-z0-9\-_]+$/i', $trimmed) !== 1) {
            throw new InvalidArgumentException('TenantId must be a non-empty alphanumeric string (hyphens and underscores allowed).');
        }

        $this->value = $trimmed;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toString(): string
    {
        return $this->value;
    }
}
