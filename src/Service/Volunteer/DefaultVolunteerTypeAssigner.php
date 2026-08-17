<?php

namespace App\Service\Volunteer;

use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Repository\UserVolunteerTypeRepository;
use App\Repository\VolunteerTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Gives a user the base volunteer type for who they are.
 *
 * Onboarding and the repair screen both go through here. They were the same twenty lines in two
 * places, and a user who finished onboarding before the default existed has to be repaired with
 * exactly the rule that would have applied to them, not an approximation of it.
 *
 * The type is matched on its role, never on its name: an event renames these to suit itself, and a
 * lookup keyed on the label stops finding them the moment it does. A type carries no role unless
 * somebody gives it one, so an event that recreates its base type instead of renaming the seeded
 * one leaves this lookup finding nothing. That is not a theoretical failure: it silently cost every
 * non-staff user their membership, and with it the ability to take a shift, while telling them
 * onboarding had finished. {@see missingRoles()} is what lets a screen report that state.
 */
final class DefaultVolunteerTypeAssigner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VolunteerTypeRepository $volunteerTypes,
        private readonly UserVolunteerTypeRepository $memberships,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The membership this user should hold, created and confirmed if it is missing.
     *
     * Confirmed straight away because the system grants it rather than the user asking for it:
     * left unconfirmed it would sit in somebody's queue as a request to approve, and an
     * unconfirmed membership does not make anyone eligible for a shift.
     *
     * Returns null when no type carries the role, which is a misconfiguration rather than a
     * property of this user. Callers doing one user may ignore it; callers doing many should
     * report it, because every single one of them will fail the same way.
     */
    public function assign(User $user): ?UserVolunteerType
    {
        $type = $this->volunteerTypes->findDefaultFor($user);
        if (null === $type) {
            $this->logger->error('No volunteer type carries the role needed to assign a default.', [
                'user' => (string) $user->getUuid(),
                'role' => $this->roleFor($user),
            ]);

            return null;
        }

        $membership = $this->memberships->findOneByUserAndType($user, $type);
        if (null === $membership) {
            $membership = new UserVolunteerType($user, $type);
            $this->em->persist($membership);
        }

        if (!$membership->isConfirmed()) {
            $membership->setConfirmedBy($user);
        }

        return $membership;
    }

    /** Whether this user already holds the base type for who they are, confirmed. */
    public function isSatisfied(User $user): bool
    {
        $type = $this->volunteerTypes->findDefaultFor($user);
        if (null === $type) {
            return false;
        }

        return $this->memberships->findOneByUserAndType($user, $type)?->isConfirmed() === true;
    }

    public function defaultFor(User $user): ?VolunteerType
    {
        return $this->volunteerTypes->findDefaultFor($user);
    }

    /**
     * The roles that no volunteer type claims, in the order a screen should show them.
     *
     * Empty means the assignment can work for everybody. Anything in it means every user of that
     * kind is silently getting nothing, so a screen that can repair users must show this first:
     * repairing users while a role is unclaimed fixes nobody.
     *
     * @return list<string>
     */
    public function missingRoles(): array
    {
        $missing = [];
        foreach ([VolunteerType::ROLE_VOLUNTEER, VolunteerType::ROLE_STAFF] as $role) {
            if (null === $this->volunteerTypes->findOneByRole($role)) {
                $missing[] = $role;
            }
        }

        return $missing;
    }

    private function roleFor(User $user): string
    {
        return $user->isStaff() ? VolunteerType::ROLE_STAFF : VolunteerType::ROLE_VOLUNTEER;
    }
}
