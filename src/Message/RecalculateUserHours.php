<?php

namespace App\Message;

/**
 * Recalculate one user's cached hours.
 *
 * Carries the id rather than the entity: the message may sit on the transport for a while, and the
 * worker consuming it has its own entity manager.
 */
final readonly class RecalculateUserHours
{
    public function __construct(public int $userId)
    {
    }
}
