<?php

namespace App\Service\Call;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\HelpCall;
use App\Entity\HelpCallResponse;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Enum\HelpCallStatus;
use App\Enum\HelpResponseType;
use App\Enum\ShiftEntryState;
use App\Mercure\ShiftSignal;
use App\Mercure\Topics;
use App\Mercure\UpdatePublisher;
use App\Repository\HelpCallRepository;
use App\Repository\HelpCallResponseRepository;
use App\Repository\OperationalStatusOverrideRepository;
use App\Repository\ShiftEntryRepository;
use App\Repository\UserVolunteerTypeRepository;
use App\Service\Availability\AvailabilityService;
use App\Service\EventConfigStore;
use App\Service\OperationalStatusService;
use App\Service\Shift\ShiftConcurrency;
use App\Service\Shift\ShiftVisibilityResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Global Call for Help. A caller opens a call requesting open slots;
 * eligible recipients (Free to help, eligible, not already assigned, not having
 * refused, permitted to see the audience) may accept - transactionally, so the
 * requested target is never exceeded - or refuse, which suppresses further
 * involvement. Filling the target, the shift ending, or a cancel closes it.
 */
final class HelpCallService
{

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HelpCallRepository $calls,
        private readonly HelpCallResponseRepository $responses,
        private readonly ShiftEntryRepository $entries,
        private readonly UserVolunteerTypeRepository $memberships,
        private readonly ShiftVisibilityResolver $visibility,
        private readonly AvailabilityService $availability,
        private readonly OperationalStatusService $status,
        private readonly ShiftConcurrency $concurrency,
        private readonly EventConfigStore $config,
        private readonly AuditLogger $audit,
        private readonly UpdatePublisher $live,
        private readonly OperationalStatusOverrideRepository $freeToHelp,
        private readonly ShiftSignal $shiftSignal,
    ) {
    }

    /**
     * Whether the caller may trigger a call for this shift now. Info Desk may trigger at any time up
     * to the end of the shift; a manager only inside the configured lead before it starts.
     *
     * That lead is in SECONDS. The key is stored, defaulted and labelled in seconds everywhere it is
     * written, and reading it as minutes multiplies the window by sixty: the configured five minutes
     * becomes five hours, and the whole event can be called for help before anybody is due.
     */
    public function canTriggerNow(User $caller, Shift $shift, bool $isInfoDesk, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        if ($now > $shift->getEndsAt()) {
            return false;
        }
        if ($isInfoDesk) {
            return true;
        }

        $lead = $this->config->getInt(EventConfigStore::KEY_CALL_MANAGER_LEAD, EventConfigStore::DEFAULT_CALL_MANAGER_LEAD);

        return $now >= $shift->getStartsAt()->modify(\sprintf('-%d seconds', $lead));
    }

    /**
     * Open a call, or return the one already active for this shift.
     *
     * Two signals, and both are needed. The eligible users are signalled because a call nobody has
     * seen yet has no previous eligible set, so signalling the current one is what puts it on their
     * board. The shift's department is signalled separately because the operations boards show the
     * call's state on the shift's row and watch the department rather than their own eligibility, so
     * the per-user fan-out never reaches them.
     */
    public function trigger(Shift $shift, ?User $caller, int $slots): HelpCall
    {
        $existing = $this->calls->findActiveForShift($shift);
        if ($existing !== null) {
            return $existing;
        }
        $call = new HelpCall($shift, $caller, $slots);
        $this->em->persist($call);
        $this->em->flush();
        $this->live->signal(array_map(
            static fn (User $user) => Topics::userCalls($user),
            $this->eligibleUsersFor($call),
        ));

        $this->shiftSignal->staffingChanged($shift);

        $this->audit->log(AuditEvents::CALL, AuditEvents::CREATE, [
            'resourceType' => 'help_call', 'resourceId' => (string) $call->getId(),
            'details' => ['shift' => $shift->getTitle(), 'slots' => $slots],
        ]);

        return $call;
    }

    /**
     * Whether the user is eligible to be offered / accept this call: the call is still open, they
     * have not refused it, they are not already on the shift, they may see it, they are Free to help,
     * they hold no confirmed assignment overlapping it, and they hold a role the shift asks for.
     */
    public function isEligible(HelpCall $call, User $user): bool
    {
        $shift = $call->getShift();
        if (!$call->isActive() || $call->slotsRemaining() <= 0) {
            return false;
        }
        if ($this->hasRefused($call, $user) || $this->entries->findOneByShiftAndUser($shift, $user) !== null) {
            return false;
        }
        if (!$this->visibility->isVisibleTo($shift, $user)) {
            return false;
        }
        if ($this->status->effectiveStatus($user) !== OperationalStatusService::FREE_TO_HELP) {
            return false;
        }
        if ($this->availability->planningState($user, $shift->getStartsAt(), $shift->getEndsAt(), $shift)['occupied']) {
            return false;
        }

        return $this->eligibleType($shift, $user) !== null;
    }

    public function hasRefused(HelpCall $call, User $user): bool
    {
        return $this->responses->findOneBy(['call' => $call, 'user' => $user, 'type' => HelpResponseType::REFUSE->value]) !== null;
    }

    /**
     * Refuse a call - suppresses further notifications for it. Only this user's board is signalled:
     * refusing takes the call off theirs and nobody else's.
     */
    public function refuse(HelpCall $call, User $user): void
    {
        if ($this->responses->findOneBy(['call' => $call, 'user' => $user]) !== null) {
            return;
        }
        $this->em->persist(new HelpCallResponse($call, $user, HelpResponseType::REFUSE));
        $this->em->flush();

        $this->live->signal(Topics::userCalls($user));

        $this->audit->log(AuditEvents::CALL, AuditEvents::REFUSE, [
            'resourceType' => 'help_call', 'resourceId' => (string) $call->getId(), 'resourceOwnerId' => $user->getId(),
        ]);
    }

    /**
     * Accept a call, creating the assignment transactionally.
     * Rechecks eligibility and the remaining slot under a lock; a call that
     * filled first is refused.
     *
     * The eligible set is read once before the lock and once after, and both are signalled. Accepting
     * removes this user from the set and may fill the call for everyone else, so a set computed only
     * afterwards would miss exactly the people whose board changed. Racing here costs at most a
     * superfluous signal, never a wrong one.
     *
     * Answering also creates an assignment, so the staffing screens and the accepter's own status
     * widget are signalled exactly as for any other assignment.
     *
     * @throws \RuntimeException when no slot remains or the user is ineligible
     */
    public function accept(HelpCall $call, User $user): ShiftEntry
    {
        $before = $this->eligibleUsersFor($call);

        $entry = $this->concurrency->transactional(function () use ($call, $user): ShiftEntry {
            $this->concurrency->lockForUpdate($call);

            if (!$this->isEligible($call, $user)) {
                throw new \RuntimeException('This call was filled or you are no longer eligible.');
            }
            $type = $this->eligibleType($call->getShift(), $user);
            if (!$type instanceof VolunteerType) {
                throw new \RuntimeException('You are not eligible for this shift.');
            }

            $entry = new ShiftEntry($call->getShift(), $type, $user);
            $entry->setState(ShiftEntryState::ASSIGNMENT);
            $this->em->persist($entry);
            $this->em->persist(new HelpCallResponse($call, $user, HelpResponseType::ACCEPT));
            $call->recordFill();
            $this->em->flush();

            $this->audit->log(AuditEvents::CALL, AuditEvents::ACCEPT, [
                'resourceType' => 'help_call', 'resourceId' => (string) $call->getId(), 'resourceOwnerId' => $user->getId(),
            ]);

            return $entry;
        });

        $topics = [];
        foreach ([...$before, ...$this->eligibleUsersFor($call)] as $affected) {
            $topics[(string) $affected->getUuid()] = Topics::userCalls($affected);
        }
        $this->live->signal(array_values($topics));

        $this->shiftSignal->staffingChanged($call->getShift(), $user);

        return $entry;
    }

    public function cancel(HelpCall $call): void
    {
        $this->signallingEligibilityChange($call, function () use ($call): void {
            $call->setStatus(HelpCallStatus::CANCELLED);
            $this->em->flush();
        });
        $this->shiftSignal->staffingChanged($call->getShift());
        $this->audit->log(AuditEvents::CALL, AuditEvents::CANCEL, [
            'resourceType' => 'help_call', 'resourceId' => (string) $call->getId(),
        ]);
    }

    /** Expire a call whose shift has ended. */
    public function expireIfDue(HelpCall $call, ?\DateTimeImmutable $now = null): bool
    {
        if ($call->isActive() && ($now ?? new \DateTimeImmutable()) > $call->getShift()->getEndsAt()) {
            $this->signallingEligibilityChange($call, function () use ($call): void {
                $call->setStatus(HelpCallStatus::EXPIRED);
                $this->em->flush();
            });

            return true;
        }

        return false;
    }

    /**
     * Tell everyone whose bounty board this change affects.
     *
     * A call is offered per user - eligibility depends on their volunteer types, their operational
     * status, the shift's audience and what else they are already doing - so there is no shared
     * "calls" topic to publish to. Each affected user is signalled on their own topic and re-fetches
     * the board, where that eligibility is applied exactly as on a full page load. Nothing about the
     * call crosses the hub.
     *
     * The set is taken before AND after the change, then combined: accepting a call removes the
     * accepter from the eligible set and may fill the call for everyone else, so a set computed only
     * afterwards would leave precisely the people whose board changed most without a signal.
     *
     * @param callable():void $change
     */
    private function signallingEligibilityChange(HelpCall $call, callable $change): void
    {
        $before = $this->eligibleUsersFor($call);
        $change();
        $after = $this->eligibleUsersFor($call);

        $topics = [];
        foreach ([...$before, ...$after] as $user) {
            $topics[(string) $user->getUuid()] = Topics::userCalls($user);
        }

        $this->live->signal(array_values($topics));
    }

    /**
     * Users who could answer this call as things stand.
     *
     * Being "Free to help" is a precondition of eligibility, so the candidate pool is the handful of
     * people currently marked as such rather than everyone at the event; each is then put through
     * the same {@see isEligible()} check the board itself uses.
     *
     * @return User[]
     */
    public function eligibleUsersFor(HelpCall $call): array
    {
        return array_values(array_filter(
            $this->freeToHelp->findFreeToHelpUsers(),
            fn (User $user) => $this->isEligible($call, $user),
        ));
    }

    /** @return HelpCall[] active calls the user may see and is eligible for (Bounty Board) */
    public function eligibleActiveCalls(User $user): array
    {
        return array_values(array_filter(
            $this->calls->findActive(),
            fn (HelpCall $call) => $this->isEligible($call, $user),
        ));
    }

    private function eligibleType(Shift $shift, User $user): ?VolunteerType
    {
        foreach ($shift->getNeededVolunteerTypes() as $need) {
            if ($this->memberships->isConfirmedMember($user, $need->getVolunteerType())) {
                return $need->getVolunteerType();
            }
        }

        return null;
    }
}
