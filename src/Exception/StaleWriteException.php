<?php

namespace App\Exception;

/**
 * Raised when a write is rejected because the underlying record changed since it
 * was loaded — e.g. a stale publication would overwrite newer planning changes.
 * Carries the versions for a helpful conflict message.
 */
final class StaleWriteException extends \RuntimeException
{
    public function __construct(
        public readonly int $expectedVersion,
        public readonly int $actualVersion,
        string $message = 'This item was changed by someone else. Reload and try again.',
    ) {
        parent::__construct($message);
    }
}
