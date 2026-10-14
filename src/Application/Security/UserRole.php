<?php

declare(strict_types=1);

namespace App\Application\Security;

enum UserRole: string
{
    case Administrator = 'administrator';
    case Editor = 'editor';
    case Author = 'author';
    case Subscriber = 'subscriber';

    public function isAdmin(): bool
    {
        return $this === self::Administrator;
    }

    public function isStaff(): bool
    {
        return $this === self::Administrator || $this === self::Editor;
    }
}
