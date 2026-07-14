<?php

namespace App\Repository;

use App\Entity\NamedPosition;
use App\Entity\Shift;
use App\Entity\ShiftPosition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShiftPosition>
 */
class ShiftPositionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShiftPosition::class);
    }

    public function findOneByShiftAndPosition(Shift $shift, NamedPosition $position): ?ShiftPosition
    {
        return $this->findOneBy(['shift' => $shift, 'namedPosition' => $position]);
    }

    /** @return ShiftPosition[] positions enabled on the shift */
    public function findForShift(Shift $shift): array
    {
        return $this->createQueryBuilder('sp')
            ->join('sp.namedPosition', 'np')->addSelect('np')
            ->join('np.group', 'g')->addSelect('g')
            ->andWhere('sp.shift = :shift')
            ->setParameter('shift', $shift)
            ->orderBy('g.displayOrder', 'ASC')
            ->addOrderBy('np.displayOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
