<?php

namespace App\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form-backing model for the documented event configuration settings.
 * The controller maps this to/from the EventConfig key-value store.
 */
final class EventConfigData
{
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    public ?string $welcomeMessage = null;

    #[Assert\Choice(choices: ['public', 'staff', 'admin'])]
    public string $accessMode = 'public';

    public ?\DateTimeImmutable $buildupStart = null;

    public ?\DateTimeImmutable $eventStart = null;

    public ?\DateTimeImmutable $eventEnd = null;

    public ?\DateTimeImmutable $teardownEnd = null;

    /** Site-wide default theme slug; users may override via /settings/theme. Empty = first catalog entry. */
    public ?string $defaultTheme = null;
}
