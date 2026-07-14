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
    public function findActive(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.status = :open')
            ->setParameter('open', HelpCallStatus::OPEN->value)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findActiveForShift(Shift $shift): ?HelpCall
    {
        return $this->findOneBy(['shift' => $shift, 'status' => HelpCallStatus::OPEN->value]);
    }
}
