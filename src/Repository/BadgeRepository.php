<?php

namespace App\Repository;

use App\Entity\Badge;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Badge>
 */
class BadgeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Badge::class);
    }

    public function findOneBySlug(string $slug): ?Badge
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /** @return Badge[] ordered: position badges first (by priority desc), then standard by name */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('b')
            ->orderBy('b.type', 'ASC')
            ->addOrderBy('b.priority', 'DESC')
            ->addOrderBy('b.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
