<?php

namespace App\Repository;

use App\Entity\Question;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Question>
 */
class QuestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Question::class);
    }

    /** @return Question[] unanswered first, then newest */
    public function findForModeration(): array
    {
        return $this->createQueryBuilder('q')
            ->orderBy('q.answeredAt', 'ASC') // nulls first in PostgreSQL ASC
            ->addOrderBy('q.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return Question[] a user's own questions, newest first */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'DESC']);
    }

    public function countUnanswered(): int
    {
        return (int) $this->createQueryBuilder('q')
            ->select('COUNT(q.id)')
            ->andWhere('q.answer IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
