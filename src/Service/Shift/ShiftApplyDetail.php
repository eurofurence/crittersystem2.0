<?php

namespace App\Service\Shift;

use App\Entity\Shift;
use App\Entity\User;
use App\Repository\UserVolunteerTypeRepository;
use App\Service\Assignment\EventHoursGuard;
use App\Service\Availability\AvailabilityService;
use App\Service\ShiftSignupService;
use App\Service\VolunteerTypeVisibility;

/**
 * Everything the shift dialog on the staff application screen shows, including why the volunteer
 * cannot apply when they cannot.
 *
 * "Ineligible" on its own is not an answer anybody can act on. Each role therefore reports what
 * specifically stands in the way, and whether that thing is something the volunteer can fix
 * themselves: a role they are not a member of, or a certification they do not hold. The links that
 * go with those are only offered when the target page would actually let them in, so the dialog
 * never hands out a dead end.
 */
final class ShiftApplyDetail
{
    public function __construct(
        private readonly ShiftSignupService $signup,
        private readonly ShiftEligibility $eligibility,
        private readonly ShiftGroupResolver $groups,
        private readonly UserVolunteerTypeRepository $memberships,
        private readonly VolunteerTypeVisibility $typeVisibility,
        private readonly AvailabilityService $availability,
        private readonly EventHoursGuard $hoursGuard,
        private readonly CheckInPolicy $checkIn,
    ) {
    }

    /**
     * @return array{
     *     shift: Shift,
     *     status: string,
     *     options: array<string, \App\Entity\VolunteerType>,
     *     roles: list<array<string, mixed>>,
     *     members: list<Shift>,
     *     overHours: bool,
     *     recommendedMax: int,
     *     availability: array{occupied: bool, value: ?\App\Enum\AvailabilityValue},
     *     blockers: list<string>,
     *     entries: list<\App\Entity\ShiftEntry>
     * }
     */
    public function build(Shift $shift, User $user): array
    {
        $members = $this->groups->membersFor($shift);
        $siblings = $this->groups->siblingsOf($shift);
        $options = $this->signup->signupOptions($shift, $user);

        $roles = [];
        foreach ($this->eligibility->availability($shift) as $row) {
            $type = $row['type'];
            $missing = $this->eligibility->missingCertifications($user, $type);
            $membership = $this->memberships->findOneByUserAndType($user, $type);
            $roles[] = [
                'type' => $type,
                'needed' => $row['needed'],
                'assigned' => $row['assigned'],
                'full' => $row['needed'] > 0 && $row['assigned'] >= $row['needed'],
                'applicable' => isset($options[(string) $type->getUuid()]),
                'reason' => $this->signup->signUpError($user, $shift, $type),
                'missingCertifications' => $missing,
                'membership' => $membership,
                'canRequestType' => $membership === null && $this->typeVisibility->isVisible($type, $user),
                'canSeeType' => $this->typeVisibility->isVisible($type, $user),
            ];
        }

        return [
            'shift' => $shift,
            'status' => $this->signup->eligibilityStatus($shift, $user),
            'options' => $options,
            'roles' => $roles,
            'members' => $members,
            'overHours' => $this->hoursGuard->wouldExceedGroup($user, $shift),
            'recommendedMax' => $this->hoursGuard->recommendedMax(),
            'availability' => $this->availability->planningState(
                $user,
                $shift->getStartsAt(),
                $shift->getEndsAt(),
                $shift,
                $siblings,
            ),
            'blockers' => $this->blockers($shift, $user, $roles),
            'entries' => $this->groups->entriesFor($shift, $user),
        ];
    }

    /**
     * Reasons that stand in the way of the shift as a whole rather than of one role, so the dialog
     * can lead with them instead of repeating the same sentence under every role.
     *
     * @param list<array<string, mixed>> $roles
     *
     * @return list<string>
     */
    private function blockers(Shift $shift, User $user, array $roles): array
    {
        $blockers = [];
        if ($shift->isPast()) {
            $blockers[] = 'shift_manager.reason.past';
        }
        if (($this->checkIn->checkInError($shift, $user)) !== null) {
            $blockers[] = 'shift_manager.reason.check_in';
        }
        if ($this->availability->planningState($user, $shift->getStartsAt(), $shift->getEndsAt(), $shift, $this->groups->siblingsOf($shift))['occupied']) {
            $blockers[] = 'shift_manager.reason.overlap';
        }
        if ($roles === []) {
            $blockers[] = 'shift_manager.reason.no_roles';
        }
        if (!$this->groups->isFullyVisibleTo($shift, $user)) {
            $blockers[] = 'shift_manager.reason.group_hidden';
        }

        return $blockers;
    }
}
