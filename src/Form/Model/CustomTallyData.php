<?php

namespace App\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/** One free-form row of the fun tallies: something this event counted that the catalog has no slug for. */
final class CustomTallyData
{
    #[Assert\Length(max: 80)]
    public ?string $label = null;

    #[Assert\PositiveOrZero]
    public ?float $amount = null;

    /** A row is kept only when both halves are present; a half-filled row is discarded silently. */
    public function isComplete(): bool
    {
        return $this->label !== null && trim($this->label) !== '' && $this->amount !== null;
    }
}
