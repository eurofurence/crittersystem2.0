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

    /**
     * The moderation queue: everything still waiting for an answer first, newest first within each
     * group.
     *
     * Ordered on whether an answer exists rather than on `answeredAt` itself. PostgreSQL sorts
     * nulls last for an ascending order, so ordering on the timestamp put exactly the questions
     * this screen exists to work through at the bottom of it.
     *
     * @return Question[]
     */
    public function findForModeration(): array
    {
        return $this->createQueryBuilder('q')
            ->addSelect('CASE WHEN q.answeredAt IS NULL THEN 0 ELSE 1 END AS HIDDEN answered')
            ->orderBy('answered', 'ASC')
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
