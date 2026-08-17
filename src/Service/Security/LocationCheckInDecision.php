<?php

namespace App\Service\Security;

/**
 * Whether somebody may be admitted, and if not, which rule stopped them.
 *
 * The reasons are carried rather than collapsed into a boolean because the screen has to tell the
 * operator what to do next: "no registration number" is a trip to the info desk, "no shift yet" is
 * a matter of coming back later, and the two are not interchangeable at a door.
 */
final readonly class LocationCheckInDecision
{
    public const REASON_NO_REGISTRATION = 'no_registration_number';
    public const REASON_NO_SHIFT = 'no_qualifying_shift';

    /** @param list<string> $reasons */
    private function __construct(
        public bool $allowed,
        public array $reasons,
    ) {
    }

    public static function allow(): self
    {
        return new self(true, []);
    }

    /** @param list<string> $reasons */
    public static function refuse(array $reasons): self
    {
        return new self(false, $reasons);
    }

    public function refusedFor(string $reason): bool
    {
        return \in_array($reason, $this->reasons, true);
    }
}
