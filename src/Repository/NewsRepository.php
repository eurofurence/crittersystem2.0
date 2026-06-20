<?php

namespace App\Repository;

use App\Entity\News;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<News>
 */
class NewsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, News::class);
    }

    /**
     * News feed: pinned first, then newest. Staff-only items are hidden unless
     * $includeStaffOnly is true.
     *
     * @return News[]
     */
    public function findFeed(bool $includeStaffOnly): array
    {
        $qb = $this->createQueryBuilder('n')
            ->orderBy('n.isPinned', 'DESC')
            ->addOrderBy('n.createdAt', 'DESC');

        if (!$includeStaffOnly) {
            $qb->andWhere('n.staffOnly = false');
        }

        return $qb->getQuery()->getResult();
    }
}
