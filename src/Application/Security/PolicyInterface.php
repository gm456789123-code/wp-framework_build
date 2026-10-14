<?php

declare(strict_types=1);

namespace App\Application\Security;

interface PolicyInterface
{
    public function can(SecurityUser $user, string $action, ?object $subject = null): bool;
}
