<?php

namespace App\Repository;

use App\Entity\GoodieItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GoodieItem>
 */
class GoodieItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GoodieItem::class);
    }

    /** @return GoodieItem[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('i')
            ->join('i.category', 'c')
            ->addSelect('c')
            ->orderBy('c.displayOrder', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->addOrderBy('i.displayOrder', 'ASC')
            ->addOrderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return GoodieItem[] active items in active categories, for distribution */
    public function findActiveForDistribution(): array
    {
        return $this->createQueryBuilder('i')
            ->join('i.category', 'c')
            ->addSelect('c')
            ->andWhere('i.isActive = true')
            ->andWhere('c.isActive = true')
            ->orderBy('c.displayOrder', 'ASC')
            ->addOrderBy('i.requiredHours', 'ASC')
            ->addOrderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
