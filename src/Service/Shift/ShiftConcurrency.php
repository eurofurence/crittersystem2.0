<?php

namespace App\Service\Shift;

use App\Entity\Shift;
use App\Exception\StaleWriteException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;

/**
 * Backend concurrency primitives for the shift model: a pessimistic
 * write lock to serialize capacity-sensitive changes, and optimistic version
 * checks to reject stale publications. These are transport-independent - the
 * frontend refresh convention complements them but is not relied upon.
 */
final class ShiftConcurrency
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Take a row-level write lock on an entity, serializing concurrent decisions
     * (last-slot races, exclusive positions, conversation claims). Must run
     * inside a transaction - see {@see transactional()}.
     */
    public function lockForUpdate(object $entity): void
    {
        $this->em->lock($entity, LockMode::PESSIMISTIC_WRITE);
    }

    /**
     * Reject a stale publication: the shift changed since it was loaded at
     * $expectedVersion.
     *
     * @throws StaleWriteException
     */
    public function assertVersion(Shift $shift, int $expectedVersion): void
    {
        try {
            $this->em->lock($shift, LockMode::OPTIMISTIC, $expectedVersion);
        } catch (OptimisticLockException) {
            throw new StaleWriteException($expectedVersion, $shift->getVersion());
        }
    }

    /**
     * Run $work in a transaction. Returns whatever the callable returns.
     *
     * @template T
     *
     * @param callable():T $work
     *
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        return $this->em->wrapInTransaction($work);
    }
}
