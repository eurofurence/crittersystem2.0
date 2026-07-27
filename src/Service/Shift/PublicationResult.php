<?php

namespace App\Service\Shift;

use App\Entity\Shift;

/**
 * Outcome of a publish attempt: the shifts that were published,
 * non-blocking warnings, and hard errors that aborted the publish.
 */
final readonly class PublicationResult
{
    /**
     * @param list<Shift>  $published
     * @param list<string> $warnings
     * @param list<string> $errors
     * @param list<string> $invalidUuids
     */
    public function __construct(
        public array $published,
        public array $warnings,
        public array $errors,
        public array $invalidUuids = [],
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->errors === [];
    }

    public function publishedCount(): int
    {
        return \count($this->published);
    }
}
