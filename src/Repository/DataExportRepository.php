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
}
