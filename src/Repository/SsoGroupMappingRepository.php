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

    /**
     * Ids of departments referenced by at least one SSO mapping, in a single
     * query (avoids an existsForDepartment() call per row on the listing).
     *
     * @return int[]
     */
    public function findLinkedDepartmentIds(): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.department) AS did')
            ->where('m.department IS NOT NULL')
            ->distinct()
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $r): int => (int) $r['did'], $rows);
    }

    /** Whether any SSO group maps to this department — i.e. it is SSO-managed. */
    public function existsForDepartment(\App\Entity\Department $department): bool
    {
        return null !== $this->createQueryBuilder('m')
            ->select('1')
            ->andWhere('m.department = :department')
            ->setParameter('department', $department)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
