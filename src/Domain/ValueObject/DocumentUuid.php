<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

use InvalidArgumentException;

final readonly class DocumentUuid
{
    private const string UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public string $value;

    public function __construct(string $value)
    {
        $trimmed = strtolower(trim($value));
        if (preg_match(self::UUID_REGEX, $trimmed) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid Document UUID: "%s".', $value));
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
