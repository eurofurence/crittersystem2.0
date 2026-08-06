<?php

namespace App\Service\Shift;

use App\Entity\Department;
use App\Entity\VolunteerType;

/**
 * Orders the volunteer types offered to one department's pickers.
 *
 * Every type is offered to every department: the vocabulary is shared, and a manager staffing a
 * shift may legitimately need a role another department also uses. Linking a type to a department
 * (on the department form) only says "this one is ours", which floats it above the types nobody
 * claimed - it never hides anything.
 *
 * Sort order still wins over the link, so the base types an admin pinned to the front (Staff 10,
 * Volunteer 20) head every picker regardless of what a department claims.
 */
final class VolunteerTypeOrdering
{
    /**
     * @param VolunteerType[] $all
     *
     * @return list<VolunteerType>
     */
    public function forDepartment(array $all, ?Department $department): array
    {
        $types = array_values($all);

        usort($types, fn (VolunteerType $a, VolunteerType $b): int => ($a->getSortOrder() <=> $b->getSortOrder())
            ?: ($this->isLinkedTo($b, $department) <=> $this->isLinkedTo($a, $department))
            ?: strcasecmp($a->getName(), $b->getName()));

        return $types;
    }

    public function isLinkedTo(VolunteerType $type, ?Department $department): bool
    {
        return $department !== null && $type->getDepartments()->contains($department);
    }
}
