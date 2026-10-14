<?php

declare(strict_types=1);

namespace App\Presentation\Cli\Support;

use App\Domain\ValueObject\PostId;
use InvalidArgumentException;

final class PostIdArgument
{
    public static function parse(string $value, string $argumentName): PostId
    {
        if (!ctype_digit($value)) {
            throw new InvalidArgumentException("Invalid {$argumentName} '{$value}', expected a positive integer.");
        }

        return new PostId((int) $value);
    }
}
