<?php

namespace App\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form-backing model for the account settings page. Mapped to the
 * user's PersonalData / Contact / Settings by the controller.
 */
final class AccountSettingsData
{
    #[Assert\Length(max: 15)]
    public ?string $pronoun = null;

    #[Assert\Length(max: 64)]
    public ?string $firstName = null;

    #[Assert\Length(max: 64)]
    public ?string $lastName = null;

    public ?\DateTimeImmutable $plannedArrivalDate = null;

    public ?\DateTimeImmutable $plannedDepartureDate = null;

    #[Assert\Length(max: 40)]
    public ?string $mobile = null;

    public string $language = 'en_US';

    public ?string $theme = null;
}
