<?php

namespace App\Service;

use App\Entity\Department;
use App\Entity\DutyRecord;
use App\Entity\User;
use App\Mercure\ShiftSignal;
use App\Repository\DutyRecordRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Start/end and summarise on-duty sessions. A user may
 * hold at most one open duty at a time.
 *
 * Going on or off duty changes who the department's operations board shows as present, so each
 * transition announces itself. The signal is published here rather than by the controller, so a
 * second caller cannot forget it.
 */
final class DutyService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DutyRecordRepository $duties,
        private readonly ShiftSignal $signal,
    ) {
    }

    public function getCurrentDuty(User $user): ?DutyRecord
    {
        return $this->duties->findActiveForUser($user);
    }

    /**
     * Start a duty in the given area. Returns the open record (existing one if
     * the user is already on duty - callers should check getCurrentDuty first).
     */
    public function startDuty(User $user, ?Department $department): DutyRecord
    {
        $active = $this->duties->findActiveForUser($user);
        if ($active !== null) {
            return $active;
        }

        $record = new DutyRecord($user, $department);
        $this->em->persist($record);
        $this->em->flush();
        $this->signal->departmentChanged($department);

        return $record;
    }

    public function endDuty(User $user): ?DutyRecord
    {
        $active = $this->duties->findActiveForUser($user);
        if ($active === null) {
            return null;
        }

        $active->setEndedAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->signal->departmentChanged($active->getDepartment());

        return $active;
    }

    /** @return DutyRecord[] */
    public function getHistory(User $user): array
    {
        return $this->duties->findByUser($user);
    }

    /** Total duty hours across all of the user's sessions (open sessions counted up to now). */
    public function totalDutyHours(User $user): float
    {
        $total = 0.0;
        foreach ($this->duties->findByUser($user) as $record) {
            $total += $record->getDurationHours();
        }

        return $total;
    }
}
