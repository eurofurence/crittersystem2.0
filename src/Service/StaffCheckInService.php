<?php

namespace App\Service;

use App\Entity\State;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Event check-in for staff.
 *
 * Staff are checked in by finishing onboarding rather than at the Info Desk desk: they are working
 * the event, and CheckInPolicy blocks shift application until someone is checked in, so without this
 * a staff member cannot apply to anything before arriving on site. Plain volunteers are unaffected
 * and still check in on arrival.
 */
final class StaffCheckInService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Checks the user in as of the moment they completed onboarding, and reports whether anything
     * changed. An existing check-in is never overwritten: the Info Desk may have recorded the real
     * arrival, which is the more accurate record of the two.
     */
    public function checkInOnboardedStaff(User $user): bool
    {
        $completedAt = $user->getOnboardingCompletedAt();
        if ($completedAt === null || !$user->isStaff()) {
            return false;
        }

        $state = $user->getState() ?? new State($user);
        if ($state->isArrived()) {
            return false;
        }

        $state->setArrived(true)->setArrivalDate($completedAt);
        $user->setState($state);
        $this->em->persist($state);

        return true;
    }
}
