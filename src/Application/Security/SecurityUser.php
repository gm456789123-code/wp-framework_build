<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Domain\ValueObject\TenantId;
use App\Domain\ValueObject\UserId;

final readonly class SecurityUser
{
    /**
     * @param list<string> $permissions
     */
    public function __construct(
        public UserId $id,
        public TenantId $tenantId,
        public UserRole $role,
        public array $permissions = [],
    ) {
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
