<?php

namespace App\Form\Model;

final class FormKitDemoData
{
    public ?string $title = null;

    /**
     * Keep as string for the demo to work nicely with datetime-local.
     * Later, we’ll use \DateTimeImmutable with proper data transformers.
     */
    public ?string $startsAt = null;

    public int $priority = 5;

    /** @var array<int, string> */
    public array $departments = [];
}
