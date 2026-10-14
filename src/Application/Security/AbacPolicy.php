<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Domain\ValueObject\ClassificationLevel;
use Override;

final readonly class AbacPolicy implements PolicyInterface
{
    #[Override]
    public function can(SecurityUser $user, string $action, ?object $subject = null): bool
    {
        // Administrator always has access
        if ($user->role->isAdmin()) {
            return true;
        }

        // Direct explicit permission check
        if ($user->hasPermission($action)) {
            return true;
        }

        // Action-specific checks
        return match ($action) {
            'page:view' => true,
            'page:create', 'page:update' => $user->role->isStaff(),
            'page:delete' => $user->role->isAdmin(),
            'vault:read' => $this->canAccessVault($user, $subject),
            'audit:view' => $user->role->isAdmin(),
            default => false,
        };
    }

    private function canAccessVault(SecurityUser $user, ?object $subject): bool
    {
        if ($subject instanceof ClassificationLevel) {
            return match ($subject) {
                ClassificationLevel::Public => true,
                ClassificationLevel::Internal => $user->role !== UserRole::Subscriber,
                ClassificationLevel::Restricted => $user->role->isStaff(),
                ClassificationLevel::Confidential => $user->role->isAdmin(),
            };
        }

        return $user->role->isStaff();
    }
}
