<?php

namespace App\Exception;

/**
 * Raised when a slot or position could not be taken because it filled first —
 * two users racing for the same final slot, or an exclusive position claimed
 * concurrently.
 */
final class CapacityConflictException extends \RuntimeException
{
    public function __construct(string $message = 'This slot was just filled. Please pick another.')
    {
        parent::__construct($message);
    }
}
