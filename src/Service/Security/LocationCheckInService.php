<?php

namespace App\Service\Security;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\LocationCheckIn;
use App\Entity\User;
use App\Enum\LocationCheckInAction;
use App\Repository\LocationCheckInRepository;
use App\Repository\ShiftEntryRepository;
use App\Service\DisplaySettings;
use App\Service\EventConfigStore;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Admitting people to the event location, and the rules about who may be admitted.
 *
 * Every decision and every write goes through here rather than through the controller, so a second
 * caller cannot forget the audit entry or the eligibility check. The log this writes is append-only:
 * {@see LocationCheckIn}.
 */
final class LocationCheckInService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LocationCheckInRepository $checkIns,
        private readonly ShiftEntryRepository $entries,
        private readonly EventConfigStore $config,
        private readonly DisplaySettings $display,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * How far ahead of a shift somebody may collect their badge, in seconds.
     *
     * Seconds because that is the unit the operations screen stores, and it is read as seconds here,
     * at the only place that consumes it.
     */
    public function windowSeconds(): int
    {
        return $this->config->getInt(
            EventConfigStore::KEY_SECURITY_CHECKIN_WINDOW,
            EventConfigStore::DEFAULT_SECURITY_CHECKIN_WINDOW,
        );
    }

    /**
     * May this person be admitted right now?
     *
     * Staff are admitted unconditionally: they run the event and are not rostered the way volunteers
     * are. Everybody else needs a registration number, which is what a wristband is issued against,
     * and a reason to be on site today.
     */
    public function decide(User $user, ?\DateTimeImmutable $now = null): LocationCheckInDecision
    {
        if ($user->isStaff()) {
            return LocationCheckInDecision::allow();
        }

        $now ??= new \DateTimeImmutable();
        $reasons = [];

        if ($user->getPersonalData()?->getBadgeNumber() === null) {
            $reasons[] = LocationCheckInDecision::REASON_NO_REGISTRATION;
        }

        if (!$this->entries->hasShiftStartingOrRunningWithin($user, $now, $this->windowSeconds())) {
            $reasons[] = LocationCheckInDecision::REASON_NO_SHIFT;
        }

        return $reasons === [] ? LocationCheckInDecision::allow() : LocationCheckInDecision::refuse($reasons);
    }

    /**
     * Admit somebody, appending a row rather than changing one.
     *
     * An override reason is required precisely when the rules say no. Passing one when the rules
     * already allow entry would record a bypass that never happened, so it is ignored in that case.
     *
     * @throws \RuntimeException when the rules refuse and no override reason is given
     */
    public function enter(User $user, ?User $actor, ?string $overrideReason = null, ?\DateTimeImmutable $now = null): LocationCheckIn
    {
        $now ??= new \DateTimeImmutable();
        $decision = $this->decide($user, $now);
        $reason = trim((string) $overrideReason);

        if (!$decision->allowed && $reason === '') {
            throw new \RuntimeException('This person does not meet the entry rules and no override reason was given.');
        }

        $row = new LocationCheckIn($user, LocationCheckInAction::ENTERED, $now, $this->localDate($now), $actor);
        if (!$decision->allowed) {
            $row->markOverridden($reason);
        }

        $this->em->persist($row);
        $this->em->flush();

        $this->audit->log(AuditEvents::SECURITY, AuditEvents::CREATE, [
            'resourceType' => 'LocationCheckIn',
            'resourceId' => (string) $user->getId(),
            'details' => array_filter([
                'action' => LocationCheckInAction::ENTERED->value,
                'overridden' => $row->isOverridden() ? 'yes' : null,
                'override_reason' => $row->getOverrideReason(),
                'refused_for' => $decision->allowed ? null : implode(',', $decision->reasons),
            ]),
        ]);

        return $row;
    }

    /** Take back an entry, by appending a withdrawal. The entry itself stays in the record. */
    public function withdraw(User $user, ?User $actor, ?\DateTimeImmutable $now = null): LocationCheckIn
    {
        $now ??= new \DateTimeImmutable();

        $row = new LocationCheckIn($user, LocationCheckInAction::WITHDRAWN, $now, $this->localDate($now), $actor);
        $this->em->persist($row);
        $this->em->flush();

        $this->audit->log(AuditEvents::SECURITY, AuditEvents::DELETE, [
            'resourceType' => 'LocationCheckIn',
            'resourceId' => (string) $user->getId(),
            'details' => ['action' => LocationCheckInAction::WITHDRAWN->value],
        ]);

        return $row;
    }

    /** Is this person inside right now, according to the last thing recorded about them today? */
    public function isInside(User $user, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        return $this->checkIns->latestForUserOnDate($user, $this->localDate($now))?->isEntry() === true;
    }

    /**
     * The calendar day an instant belongs to, in the event's timezone.
     *
     * A setup evening runs past midnight UTC in most of Europe, so taking the date off the raw
     * instant would file half a shift's arrivals under the following day and make the daily report
     * disagree with the people who worked it.
     */
    public function localDate(\DateTimeImmutable $at): \DateTimeImmutable
    {
        return new \DateTimeImmutable(
            $at->setTimezone($this->display->timezone())->format('Y-m-d'),
        );
    }
}
