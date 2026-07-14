<?php

namespace App\Repository;

use App\Entity\Department;
use App\Entity\RequestLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RequestLink>
 */
class RequestLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RequestLink::class);
    }

    public function findOneByToken(string $token): ?RequestLink
    {
        return $this->findOneBy(['token' => $token]);
    }

    /** @return RequestLink[] the department's links, newest first */
    public function findForDepartment(Department $department): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.department = :department')
            ->setParameter('department', $department)
            ->orderBy('l.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
