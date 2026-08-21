<?php

namespace App\Repository;

use App\Entity\HelpCall;
use App\Entity\Shift;
use App\Enum\HelpCallStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HelpCall>
 */
class HelpCallRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HelpCall::class);
    }

    /** @return HelpCall[] currently open calls */
    /**
     * Open calls, soonest shift first.
     *
     * Ordered on the shift rather than on when the call was raised: the board answers "who is needed
     * next", and a call put out an hour ago for a shift starting shortly matters more than one
     * raised a minute ago for tomorrow. The shift is joined because every caller reads it.
     *
     * @return HelpCall[]
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('c')
            ->join('c.shift', 's')->addSelect('s')
            ->andWhere('c.status = :open')
            ->setParameter('open', HelpCallStatus::OPEN->value)
            ->orderBy('s.startsAt', 'ASC')
            ->addOrderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findActiveForShift(Shift $shift): ?HelpCall
    {
        return $this->findOneBy(['shift' => $shift, 'status' => HelpCallStatus::OPEN->value]);
    }

    /**
     * Open calls for a whole list of shifts at once, keyed by shift id. At most one call is open per
     * shift, which {@see \App\Service\Call\HelpCallService::trigger()} guarantees.
     *
     * @param Shift[] $shifts
     *
     * @return array<int, HelpCall>
     */
    public function findActiveForShifts(array $shifts): array
    {
        $shifts = array_values(array_filter($shifts, static fn (Shift $s): bool => $s->getId() !== null));
        if ($shifts === []) {
            return [];
        }

        /** @var HelpCall[] $calls */
        $calls = $this->createQueryBuilder('c')
            ->andWhere('c.shift IN (:shifts)')
            ->andWhere('c.status = :open')
            ->setParameter('shifts', $shifts)
            ->setParameter('open', HelpCallStatus::OPEN->value)
            ->getQuery()
            ->getResult();

        $byShift = [];
        foreach ($calls as $call) {
            $byShift[$call->getShift()->getId()] = $call;
        }

        return $byShift;
    }
}
