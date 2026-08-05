<?php

namespace App\Service\Shift;

use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\VolunteerType;

/**
 * One member shift inside a {@see GroupSignupPlan}: what the volunteer would be signed up as, and
 * anything that blocks or complicates it.
 */
final class GroupSignupMember
{
    /**
     * @param VolunteerType|null       $type        the role this member would be recorded under, or
     *                                              null while the volunteer still has to choose
     * @param array<int, VolunteerType> $options    roles offered for this member, keyed by id
     * @param string|null              $error       blocking reason, or null when this member is fine
     * @param list<string>             $warnings    non-blocking notes shown in the modal
     */
    public function __construct(
        public readonly Shift $shift,
        public readonly bool $isOrigin,
        public readonly ?VolunteerType $type,
        public readonly array $options,
        public readonly ?string $error,
        public readonly array $warnings,
        public readonly int $assigned,
        public readonly int $needed,
        public readonly ?ShiftEntry $existingEntry,
    ) {
    }

    /** Whether the volunteer still has to pick a role for this member before the group can be taken. */
    public function needsChoice(): bool
    {
        return $this->type === null && $this->options !== [];
    }
}
