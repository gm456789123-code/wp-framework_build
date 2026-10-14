<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

use InvalidArgumentException;

final readonly class Email
{
    public string $value;

    public function __construct(string $value)
    {
        $trimmed = strtolower(trim($value));
        if (filter_var($trimmed, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException(sprintf('Invalid email address: "%s".', $value));
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
