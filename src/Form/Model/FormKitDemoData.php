<?php

namespace App\Form\Model;

final class FormKitDemoData
{
    public ?string $title = null;

    /** Kept as a string so the demo binds directly to a datetime-local input. */
    public ?string $startsAt = null;

    public int $priority = 5;

    /** @var array<int, string> */
    public array $departments = [];
}
