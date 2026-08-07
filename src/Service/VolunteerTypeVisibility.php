<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\VolunteerType;
use App\Repository\UserGroupAssignmentRepository;

/**
 * Which volunteer types a user may see at all.
 *
 * Staff-only types are hidden from volunteers, and a department-only type is hidden from staff
 * outside its departments. One implementation because two surfaces depend on it: the self-service
 * pages refuse anything else with a 404, so any screen that offers a link to one has to ask the same
 * question first or it hands the user a dead end.
 */
final class VolunteerTypeVisibility
{
    public function __construct(private readonly UserGroupAssignmentRepository $assignments)
    {
    }

    public function isVisible(VolunteerType $type, User $user): bool
    {
        if (!$type->isStaffOnly()) {
            return true;
        }
        if (!$user->isStaff()) {
            return false;
        }
        if (!$type->isDepartmentOnly()) {
            return true;
        }

        foreach ($type->getDepartments() as $department) {
            if ($this->assignments->userIsMember($user, $department)) {
                return true;
            }
        }

        return false;
    }
}
