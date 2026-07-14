<?php

namespace App\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form-backing model for the operational configuration.
 * The controller maps this to/from the EventConfig key-value store.
 */
final class OperationsConfigData
{
    #[Assert\Positive]
    public int $noShowThreshold = 2;

    public string $banScreenMessage = "";

    public bool $messagesEnabled = true;

    public string $infoDeskWelcome = "";

    public string $infoDeskFinalization = "";

    #[Assert\Positive]
    public int $infoDeskClaimTimeout = 300;

    #[Assert\Positive]
    public int $messageEditWindow = 60;

    #[Assert\Positive]
    public int $callResponseTimeout = 600;

    #[Assert\Positive]
    public int $callManagerLead = 300;

    #[Assert\Positive]
    public int $shiftReminderLead = 1800;

    #[Assert\Positive]
    public int $recommendedMaxHours = 20;

    public bool $autoMembershipFromLinks = false;

    #[Assert\Range(min: 0, max: 23)]
    public int $nightStartHour = 2;

    #[Assert\Range(min: 0, max: 24)]
    public int $nightEndHour = 8;

    #[Assert\Positive]
    public float $nightMultiplier = 2.0;

    public float $noShowMultiplier = -2.0;

    #[Assert\Positive]
    public int $sessionIdleMinutes = 60;
}
