<?php

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\User;
use App\Enum\ConversationStatus;
use App\Enum\ConversationType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Conversation>
 */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    public function findOpenSupportForUser(User $user): ?Conversation
    {
        return $this->findOneBy([
            'type' => ConversationType::SUPPORT->value,
            'subject' => $user,
            'status' => ConversationStatus::OPEN->value,
        ]);
    }

    /** A direct conversation involving both users. */
    public function findDirectBetween(User $a, User $b): ?Conversation
    {
        return $this->createQueryBuilder('c')
            ->join('c.participants', 'pa')
            ->join('c.participants', 'pb')
            ->andWhere('c.type = :direct')
            ->andWhere('pa.user = :a AND pb.user = :b')
            ->setParameter('direct', ConversationType::DIRECT->value)
            ->setParameter('a', $a)
            ->setParameter('b', $b)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return Conversation[] open support conversations waiting to be claimed */
    public function findWaitingSupport(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.type = :support')
            ->andWhere('c.status = :open')
            ->andWhere('c.claimedBy IS NULL')
            ->setParameter('support', ConversationType::SUPPORT->value)
            ->setParameter('open', ConversationStatus::OPEN->value)
            ->orderBy('c.updatedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Conversation[] support conversations claimed by the given owner */
    public function findClaimedBy(User $owner): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.claimedBy = :owner')
            ->andWhere('c.status = :open')
            ->setParameter('owner', $owner)
            ->setParameter('open', ConversationStatus::OPEN->value)
            ->orderBy('c.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return Conversation[] conversations the user participates in, most recent first */
    public function findForParticipant(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->join('c.participants', 'p')
            ->andWhere('p.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
