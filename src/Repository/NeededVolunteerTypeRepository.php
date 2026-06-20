<?php

namespace App\Repository;

use App\Entity\NeededVolunteerType;
use App\Entity\Shift;
use App\Entity\VolunteerType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NeededVolunteerType>
 */
class NeededVolunteerTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NeededVolunteerType::class);
    }

    /**
     * Effective staffing needs for a shift using the three-tier priority:
     * a shift-level requirement overrides a location- or
     * shift-type-level one for the same volunteer type.
     *
     * @return array<int, NeededVolunteerType> keyed by volunteer type id
     */
    public function findEffectiveForShift(Shift $shift): array
    {
        /** @var NeededVolunteerType[] $candidates */
        $candidates = $this->createQueryBuilder('n')
            ->andWhere('n.shift = :shift OR n.location = :location OR n.shiftType = :shiftType')
            ->setParameter('shift', $shift)
            ->setParameter('location', $shift->getLocation())
            ->setParameter('shiftType', $shift->getShiftType())
            ->getQuery()
            ->getResult();

        $effective = [];
        foreach ($candidates as $needed) {
            $typeId = $needed->getVolunteerType()->getId();
            $existing = $effective[$typeId] ?? null;
            if ($existing === null || $this->tier($needed) < $this->tier($existing)) {
                $effective[$typeId] = $needed;
            }
        }

        return $effective;
    }

    /** Lower tier number = higher priority: shift (0) < location (1) < shift type (2). */
    private function tier(NeededVolunteerType $needed): int
    {
        if ($needed->getShift() !== null) {
            return 0;
        }

        return $needed->getLocation() !== null ? 1 : 2;
    }

    public function findOneForShiftAndType(Shift $shift, VolunteerType $type): ?NeededVolunteerType
    {
        return $this->findOneBy(['shift' => $shift, 'volunteerType' => $type]);
    }
}
