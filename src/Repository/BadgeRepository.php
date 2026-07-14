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

    /** Resolve a badge by its public UUID (as exposed in URLs); null for a malformed uuid. */
    public function findOneByUuid(string $uuid): ?Badge
    {
        return \Symfony\Component\Uid\Uuid::isValid($uuid) ? $this->findOneBy(['uuid' => $uuid]) : null;
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
