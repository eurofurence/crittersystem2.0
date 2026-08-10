<?php

namespace App\Service\Shift;

use App\Entity\Certification;
use App\Entity\Shift;
use App\Entity\User;
use App\Repository\UserVolunteerTypeRepository;
use App\Service\VolunteerTypeVisibility;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * What a volunteer would have to acquire before a shift's roles are open to them.
 *
 * The modal shows every role the shift asks for, not only the ones blocking this viewer, so that a
 * shift which offers them nothing still explains itself.
 */
final class ShiftRequirementsResolver
{
    public function __construct(
        private readonly ShiftEligibility $eligibility,
        private readonly UserVolunteerTypeRepository $memberships,
        private readonly VolunteerTypeVisibility $typeVisibility,
        private readonly Security $security,
    ) {
    }

    /**
     * One row per role the shift needs, worst state first so the reader meets the obstacle before
     * the roles they already qualify for.
     *
     * @return list<RoleRequirement>
     */
    public function forShift(Shift $shift, User $user): array
    {
        $rows = [];
        foreach ($this->eligibility->availability($shift) as $row) {
            $type = $row['type'];
            $membership = $this->memberships->findOneByUserAndType($user, $type);
            $missing = $this->eligibility->missingCertifications($user, $type);

            if ($membership === null) {
                $state = RoleRequirement::STATE_NOT_MEMBER;
            } elseif (!$membership->isConfirmed()) {
                $state = RoleRequirement::STATE_PENDING;
            } elseif ($missing !== []) {
                $state = RoleRequirement::STATE_MISSING_CERTIFICATION;
            } elseif ($row['assigned'] >= $row['needed']) {
                $state = RoleRequirement::STATE_FULL;
            } else {
                $state = RoleRequirement::STATE_ELIGIBLE;
            }

            $rows[] = new RoleRequirement(
                $type,
                $row['assigned'],
                $row['needed'],
                $state,
                $missing,
                $this->typeVisibility->isVisible($type, $user),
                array_values(array_filter($missing, fn (Certification $c): bool => $this->canSeeCertification($c))),
            );
        }

        usort($rows, static fn (RoleRequirement $a, RoleRequirement $b): int => self::rank($a->state) <=> self::rank($b->state));

        return $rows;
    }

    /**
     * The same rule {@see \App\Controller\CertificationController::show()} enforces. A certification
     * the viewer may not open is still named - the shift already advertises the role - but carries
     * no link, so the modal never offers a route to a 404.
     */
    private function canSeeCertification(Certification $certification): bool
    {
        return !$certification->isStaffOnly()
            || $this->security->isGranted('ROLE_STAFF')
            || $this->security->isGranted('global:admin');
    }

    private static function rank(string $state): int
    {
        return match ($state) {
            RoleRequirement::STATE_NOT_MEMBER => 0,
            RoleRequirement::STATE_PENDING => 1,
            RoleRequirement::STATE_MISSING_CERTIFICATION => 2,
            RoleRequirement::STATE_FULL => 3,
            default => 4,
        };
    }
}
