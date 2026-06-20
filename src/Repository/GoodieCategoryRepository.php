<?php

namespace App\Repository;

use App\Entity\GoodieCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GoodieCategory>
 */
class GoodieCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GoodieCategory::class);
    }

    /** @return GoodieCategory[] */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['displayOrder' => 'ASC', 'name' => 'ASC']);
    }
}
