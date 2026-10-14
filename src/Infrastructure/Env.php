<?php

declare(strict_types=1);

namespace App\Infrastructure;

final class Env
{
    public static function string(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    public static function int(string $key, int $default): int
    {
        $value = $_ENV[$key] ?? null;

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return is_int($value) ? $value : $default;
    }
}
