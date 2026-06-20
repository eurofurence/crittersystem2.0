<?php

namespace App\Repository;

use App\Entity\Message;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /** All messages involving the user, newest first (used to build the inbox). */
    public function findInvolving(User $user): array
    {
        return $this->createQueryBuilder('m')
            ->join('m.sender', 's')->addSelect('s')
            ->join('m.receiver', 'r')->addSelect('r')
            ->andWhere('m.sender = :u OR m.receiver = :u')
            ->setParameter('u', $user)
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Messages exchanged between two users, oldest first. */
    public function findConversation(User $a, User $b): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('(m.sender = :a AND m.receiver = :b) OR (m.sender = :b AND m.receiver = :a)')
            ->setParameter('a', $a)
            ->setParameter('b', $b)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countUnread(User $user): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.receiver = :u AND m.isRead = false')
            ->setParameter('u', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Mark all messages from $other to $reader as read. */
    public function markConversationRead(User $reader, User $other): void
    {
        $this->createQueryBuilder('m')
            ->update()
            ->set('m.isRead', 'true')
            ->andWhere('m.receiver = :reader AND m.sender = :other AND m.isRead = false')
            ->setParameter('reader', $reader)
            ->setParameter('other', $other)
            ->getQuery()
            ->execute();
    }
}
