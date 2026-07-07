<?php

declare(strict_types=1);

namespace App\Audit;

use App\Entity\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Persists dispatched audit records into the append-only audit_events table.
 * Runs on the async worker, so the database write is kept off the request path.
 */
#[AsMessageHandler]
final class AuditEventHandler
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(AuditRecord $record): void
    {
        $this->em->persist(new AuditEvent($record));
        $this->em->flush();
    }
}
