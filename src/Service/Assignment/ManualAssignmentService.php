<?php

namespace App\Service\Assignment;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\ShiftPosition;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Enum\AvailabilityValue;
use App\Enum\ShiftEntryState;
use App\Repository\ShiftEntryRepository;
use App\Service\Availability\AvailabilityService;
use App\Service\EventConfigStore;
use App\Service\HoursCalculator;
use App\Service\Shift\PositionService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Manager-driven shift/position assignment. Surfaces the warnings a
 * manager should see before publishing (availability Avoid/Unavailable/occupied,
 * over recommended event hours) and requires an explicit override to assign
 * against them. Overrides are visibly marked on the entry and audited.
 */
final class ManualAssignmentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShiftEntryRepository $entries,
        private readonly AvailabilityService $availability,
        private readonly PositionService $positions,
        private readonly HoursCalculator $hours,
        private readonly EventConfigStore $config,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Warnings a manager should weigh before assigning this user to this shift.
     * Each carries a machine key and a human message.
     *
     * @return array{needsOverride: bool, warnings: list<array{key: string, message: string}>}
     */
    public function inspect(Shift $shift, User $user): array
    {
        $warnings = [];
        $needsOverride = false;

        $state = $this->availability->planningState($user, $shift->getStartsAt(), $shift->getEndsAt(), $shift);
        if ($state['occupied']) {
            $needsOverride = true;
            $warnings[] = ['key' => 'occupied', 'message' => 'The user already has a confirmed assignment overlapping this shift.'];
        } elseif ($state['value'] !== null && $state['value']->needsOverride()) {
            $needsOverride = true;
            $warnings[] = ['key' => 'availability', 'message' => \sprintf('The user marked this time as "%s".', $state['value']->label())];
        }

        $recommended = $this->config->getInt(EventConfigStore::KEY_HOURS_RECOMMENDED_MAX, EventConfigStore::DEFAULT_HOURS_RECOMMENDED_MAX);
        $projected = $this->hours->totalForUser($user) + $shift->getDurationHours();
        if ($recommended > 0 && $projected > $recommended) {
            $needsOverride = true;
            $warnings[] = ['key' => 'hours', 'message' => \sprintf('This would bring the user to %.1f planned hours (recommended max %d).', $projected, $recommended)];
        }

        return ['needsOverride' => $needsOverride, 'warnings' => $warnings];
    }

    /**
     * Assign a user to a shift as the given volunteer type, returning the single
     * shift entry. When warnings apply the caller must pass
     * $override; otherwise this refuses. An override is marked on the entry and
     * audited.
     *
     * @throws \RuntimeException when an override is required but not confirmed
     */
    public function assign(Shift $shift, User $user, VolunteerType $type, bool $override = false, ?User $actor = null): ShiftEntry
    {
        $entry = $this->entries->findOneByShiftAndUser($shift, $user);
        if ($entry !== null) {
            return $entry;
        }

        $inspection = $this->inspect($shift, $user);
        if ($inspection['needsOverride'] && !$override) {
            $messages = array_map(static fn ($w) => $w['message'], $inspection['warnings']);
            throw new \RuntimeException('This assignment needs an override: '.implode(' ', $messages));
        }

        $entry = new ShiftEntry($shift, $type, $user);
        $entry->setState(ShiftEntryState::ASSIGNMENT);
        if ($inspection['needsOverride']) {
            $entry->markOverridden(implode('; ', array_map(static fn ($w) => $w['key'], $inspection['warnings'])));
        }
        $this->em->persist($entry);
        $this->em->flush();

        $this->audit->log(AuditEvents::SHIFT, AuditEvents::ASSIGN, [
            'resourceType' => 'shift', 'resourceId' => (string) $shift->getId(), 'resourceOwnerId' => $user->getId(),
            'details' => ['user' => $user->getUserIdentifier()],
        ]);
        if ($entry->isOverridden()) {
            $this->audit->log(AuditEvents::SHIFT, AuditEvents::OVERRIDE, [
                'resourceType' => 'shift', 'resourceId' => (string) $shift->getId(), 'resourceOwnerId' => $user->getId(),
                'details' => ['reason' => $entry->getOverrideReason()],
            ]);
        }

        return $entry;
    }

    /** Assign a user to a named position, ensuring their shift entry first. */
    public function assignToPosition(ShiftPosition $shiftPosition, User $user, VolunteerType $type, bool $override = false, ?User $actor = null): void
    {
        $this->assign($shiftPosition->getShift(), $user, $type, $override, $actor);
        $this->positions->assignUser($shiftPosition, $user, $type);
    }

    /** Remove a user's assignment from a shift; audited. */
    public function remove(ShiftEntry $entry): void
    {
        $shift = $entry->getShift();
        $user = $entry->getUser();
        $this->em->remove($entry);
        $this->em->flush();

        $this->audit->log(AuditEvents::SHIFT, AuditEvents::UNASSIGN, [
            'resourceType' => 'shift', 'resourceId' => (string) $shift->getId(), 'resourceOwnerId' => $user->getId(),
        ]);
    }
}
