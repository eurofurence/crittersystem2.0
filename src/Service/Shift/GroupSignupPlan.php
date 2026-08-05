<?php

namespace App\Service\Shift;

use App\Entity\Shift;
use App\Entity\ShiftGroup;

/**
 * What applying to a shift would actually commit the volunteer to.
 *
 * Built once and read by the confirmation modal, the bot response and the submit handler, so the
 * three cannot disagree about the roles, the hours or the reason for a refusal.
 *
 * A shift with no group produces a plan with a single member and a null group, so callers need no
 * special case.
 */
final class GroupSignupPlan
{
    /**
     * @param list<GroupSignupMember> $members     in start order, the clicked shift included
     * @param string|null             $error       reason the group cannot be taken at all
     * @param bool                    $hidesMember true when a member is not visible to this viewer;
     *                                             the hidden member is absent from $members and must
     *                                             never be described in any response
     */
    public function __construct(
        public readonly Shift $origin,
        public readonly ?ShiftGroup $group,
        public readonly array $members,
        public readonly float $totalHours,
        public readonly bool $needsHoursAcknowledgement,
        public readonly ?string $error,
        public readonly bool $hidesMember = false,
    ) {
    }

    public function isGrouped(): bool
    {
        return \count($this->members) > 1 || $this->hidesMember;
    }

    public function isApplicable(): bool
    {
        return $this->error === null && !$this->needsChoice();
    }

    /** Whether any member still needs the volunteer to pick a role. */
    public function needsChoice(): bool
    {
        foreach ($this->members as $member) {
            if ($member->needsChoice()) {
                return true;
            }
        }

        return false;
    }

    public function memberCount(): int
    {
        return \count($this->members);
    }

    /** @return list<Shift> */
    public function shifts(): array
    {
        return array_map(static fn (GroupSignupMember $m): Shift => $m->shift, $this->members);
    }
}
