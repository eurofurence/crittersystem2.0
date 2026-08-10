<?php

namespace App\Service\Shift;

use App\Entity\Certification;
use App\Entity\VolunteerType;

/**
 * One role a shift asks for, and where this volunteer stands on it.
 *
 * Read by the shift modal so a volunteer who is offered nothing can see why, and what would change
 * that. {@see ShiftEligibility} decides whether they may apply; this only describes the decision.
 */
final class RoleRequirement
{
    public const STATE_ELIGIBLE = 'eligible';
    public const STATE_PENDING = 'pending';
    public const STATE_NOT_MEMBER = 'not_member';
    public const STATE_MISSING_CERTIFICATION = 'missing_certification';
    public const STATE_FULL = 'full';

    /**
     * @param string               $state                  one of the STATE_* constants
     * @param list<Certification>  $missingCertifications  required by the role, not currently held
     * @param bool                 $typeLinkable           false when the volunteer type page would
     *                                                     404 for this viewer, so it is named
     *                                                     without a link
     * @param list<Certification>  $linkableCertifications the subset of $missingCertifications whose
     *                                                     page this viewer may open
     */
    public function __construct(
        public readonly VolunteerType $type,
        public readonly int $assigned,
        public readonly int $needed,
        public readonly string $state,
        public readonly array $missingCertifications = [],
        public readonly bool $typeLinkable = true,
        public readonly array $linkableCertifications = [],
    ) {
    }

    public function isSatisfied(): bool
    {
        return $this->state === self::STATE_ELIGIBLE;
    }
}
