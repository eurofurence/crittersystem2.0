<?php

namespace App\Repository;

use App\Entity\Department;
use App\Entity\InternalNote;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InternalNote>
 */
class InternalNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternalNote::class);
    }

    /**
     * Notes filtered by optional category / department / subject user, newest first.
     *
     * @return InternalNote[]
     */
    public function findFiltered(?string $category = null, ?Department $department = null, ?User $subject = null): array
    {
        $qb = $this->createQueryBuilder('n')
            ->leftJoin('n.author', 'a')->addSelect('a')
            ->leftJoin('n.subjectUser', 's')->addSelect('s')
            ->leftJoin('n.department', 'd')->addSelect('d')
            ->orderBy('n.createdAt', 'DESC');

        if ($category !== null && $category !== '') {
            $qb->andWhere('n.category = :category')->setParameter('category', $category);
        }
        if ($department !== null) {
            $qb->andWhere('n.department = :department')->setParameter('department', $department);
        }
        if ($subject !== null) {
            $qb->andWhere('n.subjectUser = :subject')->setParameter('subject', $subject);
        }

        return $qb->getQuery()->getResult();
    }
}
