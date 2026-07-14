<?php

namespace App\Enum;

/**
 * Whether a shift entry is a volunteer-initiated application or a
 * manager-confirmed assignment. Both occupy a slot; the
 * distinction drives review workflows and audience/eligibility rules.
 */
enum ShiftEntryState: string
{
    /** The user applied and awaits confirmation where a workflow requires it. */
    case APPLICATION = 'application';

    /** The user is confirmed for the shift (self-signup or manager assignment). */
    case ASSIGNMENT = 'assignment';

    public function label(): string
    {
        return match ($this) {
            self::APPLICATION => 'Application',
            self::ASSIGNMENT => 'Assignment',
        };
    }
}
