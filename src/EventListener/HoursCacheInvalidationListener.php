<?php

namespace App\EventListener;

use App\Entity\ShiftEntry;
use App\Entity\Worklog;
use App\Repository\UserHoursCacheRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Marks a user's cached hours stale whenever the data they are derived from changes.
 *
 * This hangs off the flush rather than off the controllers that write worklogs and shift entries,
 * because the defect it fixes was exactly that invalidation was something a caller had to remember:
 * six write paths existed and none of them did it, so the hours only ever moved when the daily TTL
 * expired or somebody pressed a button. A new write path cannot forget this one.
 *
 * The users are collected during the flush, while the unit of work still knows what changed, and
 * the flag is written afterwards in one statement. Writing during the flush would schedule more
 * changes inside the flush that triggered it.
 *
 * A shift ending is deliberately not handled here. Nothing writes at that moment, so there is
 * nothing to listen to; the sweep finds those by comparing the shift's end against the row's own
 * timestamp.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class HoursCacheInvalidationListener
{
    /** @var int[] */
    private array $pending = [];

    public function __construct(private readonly UserHoursCacheRepository $caches)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();

        $changed = [
            ...$unitOfWork->getScheduledEntityInsertions(),
            ...$unitOfWork->getScheduledEntityUpdates(),
            ...$unitOfWork->getScheduledEntityDeletions(),
        ];

        foreach ($changed as $entity) {
            if (!$entity instanceof Worklog && !$entity instanceof ShiftEntry) {
                continue;
            }
            $id = $entity->getUser()?->getId();
            if ($id !== null) {
                $this->pending[] = $id;
            }
        }
    }

    /**
     * The list is cleared before the write, not after: that statement is itself a database write,
     * and a listener re-entered by it would otherwise mark the same users again without end.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->pending === []) {
            return;
        }

        $userIds = $this->pending;
        $this->pending = [];

        $this->caches->markDirtyForUsers($userIds);
    }
}
