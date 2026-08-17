<?php

namespace App\Service;

use App\Entity\User;

/**
 * Profile visibility rules. Staff may view any profile; non-staff
 * may only view Department/Shift Managers. Seeing another user's shift history
 * is a separate permission. Own profile and own history are always visible.
 */
class ProfileAccessService
{
    public function canView(User $viewer, User $subject): bool
    {
        if ($this->isSelf($viewer, $subject)) {
            return true;
        }

        if ($viewer->isStaff() || $viewer->hasPrivilege('profile:view')) {
            return true;
        }

        return $this->isManager($subject);
    }

    public function canViewHistory(User $viewer, User $subject): bool
    {
        if ($this->isSelf($viewer, $subject)) {
            return true;
        }

        return $this->canView($viewer, $subject) && $viewer->hasPrivilege('profile:history:view');
    }

    private function isSelf(User $viewer, User $subject): bool
    {
        return $viewer->getId() !== null && $viewer->getId() === $subject->getId();
    }

    private function isManager(User $user): bool
    {
        return $user->hasPrivilege('shift:manage') || $user->hasPrivilege('department:manage');
    }
}
