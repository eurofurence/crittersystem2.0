<?php

namespace App\Repository;

use App\Entity\AuditExport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditExport>
 */
class AuditExportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditExport::class);
    }

    public function findOneByUuid(string $uuid): ?AuditExport
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /**
     * Exports whose retention window has passed (their files should be purged).
     *
     * @return AuditExport[]
     */
    public function findExpired(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.expiresAt <= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    /** @return AuditExport[] */
    public function findRecent(int $limit = 50): array
    {
        return $this->createQueryBuilder('e')
            ->orderBy('e.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
