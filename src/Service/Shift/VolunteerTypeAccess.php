<?php

namespace App\Service\Shift;

use App\Entity\Department;
use App\Entity\VolunteerType;

/**
 * Which volunteer types a department may staff its shifts with.
 *
 * A type that is linked to no department at all is the shared vocabulary every department draws on
 * (Staff, Volunteer and anything else an admin left unlinked). Linking a type to departments makes
 * it theirs: it stops appearing in the pickers of every other department, so one department's
 * specialised roles do not clutter - or get attached to - another's shifts.
 *
 * This is a relevance rule rather than a secrecy one; the names are not confidential. It is still
 * enforced on write as well as on render, so a type cannot be attached to a shift whose department
 * does not offer it.
 */
final class VolunteerTypeAccess
{
    /**
     * @param VolunteerType[] $all
     *
     * @return list<VolunteerType>
     */
    public function forDepartment(array $all, ?Department $department): array
    {
        return array_values(array_filter(
            $all,
            fn (VolunteerType $type) => $this->isAvailableTo($type, $department),
        ));
    }

    public function isAvailableTo(VolunteerType $type, ?Department $department): bool
    {
        if ($type->getDepartments()->isEmpty()) {
            return true;
        }

        return $department !== null && $type->getDepartments()->contains($department);
    }
}
