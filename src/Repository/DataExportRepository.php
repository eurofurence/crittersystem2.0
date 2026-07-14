<?php

namespace App\Repository;

use App\Entity\DataExport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DataExport>
 */
class DataExportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DataExport::class);
    }

    public function findOneByUuid(string $uuid): ?DataExport
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /**
     * Expired exports that still hold an archive. The archive is a full copy of the user's personal
     * data, so it must not outlive the download window it was created for.
     *
     * @return DataExport[]
     */
    public function findExpiredWithArchive(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.expiresAt <= :now')
            ->andWhere('e.storageKey IS NOT NULL')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }
}
