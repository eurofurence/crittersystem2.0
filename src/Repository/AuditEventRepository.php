<?php

namespace App\Repository;

use App\Entity\AuditEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditEvent>
 */
class AuditEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditEvent::class);
    }

    /**
     * Events for a legal export: everything in the time window, optionally
     * narrowed to a focus user — but system and internal actions are always
     * included even when a user is the focus (task requirement).
     *
     * @return iterable<AuditEvent>
     */
    public function streamForExport(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $focusUserId): iterable
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.occurredAt >= :from')
            ->andWhere('e.occurredAt <= :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('e.occurredAt', 'ASC')
            ->addOrderBy('e.id', 'ASC');

        if ($focusUserId !== null) {
            $qb->andWhere('e.actorUserId = :uid OR e.resourceOwnerId = :uidStr OR e.actorType = :system')
                ->setParameter('uid', $focusUserId)
                ->setParameter('uidStr', (string) $focusUserId)
                ->setParameter('system', 'system');
        }

        return $qb->getQuery()->toIterable();
    }

    /**
     * Recent events for the on-screen viewer, newest first.
     *
     * @return AuditEvent[]
     */
    public function findRecent(?string $eventType, ?int $actorUserId, int $limit = 200): array
    {
        $qb = $this->createQueryBuilder('e')
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit);

        if ($eventType !== null && $eventType !== '') {
            $qb->andWhere('e.eventType = :type')->setParameter('type', $eventType);
        }
        if ($actorUserId !== null) {
            $qb->andWhere('e.actorUserId = :uid')->setParameter('uid', $actorUserId);
        }

        return $qb->getQuery()->getResult();
    }
}
