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
        $this->live->signal(Topics::allCalls());

        $this->shiftSignal->staffingChanged($shift);

        $this->audit->log(AuditEvents::CALL, AuditEvents::CREATE, [
            'resourceType' => 'help_call', 'resourceId' => (string) $call->getId(),
            'details' => ['shift' => $shift->getTitle(), 'slots' => $slots],
        ]);

        return $call;
    }

    /**
     * Whether the user may be offered and may accept this call: the call is still open, they have
     * not refused it, they are not already on the shift, they may see it, they are not in the middle
     * of a shift, they hold no confirmed assignment overlapping it, and they hold a role the shift
     * asks for.
     *
     * Being marked Free to help is deliberately not required. Requiring it meant the board was empty
     * for everybody who had not opted in, which is most people, so calls went unanswered while
     * volunteers who would gladly have helped saw nothing. The status now decides who is interrupted
     * by a live update, not who is allowed to answer.
     *
     * Somebody already working a shift is still excluded: their status is derived from that shift
     * rather than chosen, and asking them to leave what they are doing is not what a call is for.
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
        if ($this->status->effectiveStatus($user) === OperationalStatusService::NOT_AVAILABLE) {
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

        $this->live->signal(Topics::allCalls());

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
    /**
     * Apply a change that alters who may answer a call, then tell every board to re-read.
     *
     * The before and after sets of candidates used to be computed so that both could be signalled
     * individually. One shared topic makes that unnecessary: everybody re-reads and the controller
     * decides what each of them sees.
     */
    private function signallingEligibilityChange(HelpCall $call, callable $change): void
    {
        $change();

        $this->live->signal(Topics::allCalls());
    }

    /**
     * The open calls this user may answer, soonest shift first.
     *
     * Ordered by when help is actually needed rather than by when the call was raised: a call put
     * out an hour ago for a shift starting in ten minutes matters more than one raised a minute ago
     * for tomorrow.
     *
     * @return HelpCall[]
     */
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
