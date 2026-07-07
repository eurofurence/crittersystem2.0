<?php

namespace App\Repository;

use App\Entity\SsoGroupMapping;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SsoGroupMapping>
 */
class SsoGroupMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SsoGroupMapping::class);
    }

    public function findOneBySsoGroupId(string $ssoGroupId): ?SsoGroupMapping
    {
        return $this->findOneBy(['ssoGroupId' => $ssoGroupId]);
    }

    /** @return SsoGroupMapping[] */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }
}
