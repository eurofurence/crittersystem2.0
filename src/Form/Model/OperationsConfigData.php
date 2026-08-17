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

    /** Every locale without its own text falls back to this one, so it may not be left empty. */
    #[Assert\NotBlank]
    public string $checkInMessageEn = "";

    public string $checkInMessageDe = "";

    #[Assert\Positive]
    public int $infoDeskClaimTimeout = 300;

    /** How far ahead of a shift somebody may collect a wristband, in seconds. */
    public int $securityCheckInWindow = 7200;

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

    #[Assert\PositiveOrZero]
    public int $boardPreStartWarnMin = 15;

    #[Assert\Positive]
    public int $boardMaxContinuousMin = 360;

    #[Assert\Positive]
    public int $boardMaxSequentialMin = 360;

    #[Assert\PositiveOrZero]
    public int $boardUnattendedMin = 10;

    #[Assert\Range(min: 0, max: 1)]
    public float $boardOverworkWarnFraction = 0.90;

    /** Ascending hour boundaries, comma separated. */
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\s*\d+(?:\.\d+)?\s*(?:,\s*\d+(?:\.\d+)?\s*)*$/', message: 'manage.operations_config.field.board_bands.invalid')]
    public string $boardCardBands = '15,20,25';

    /** Ascending hour boundaries, comma separated. */
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\s*\d+(?:\.\d+)?\s*(?:,\s*\d+(?:\.\d+)?\s*)*$/', message: 'manage.operations_config.field.board_bands.invalid')]
    public string $boardWorkloadBands = '10,20,30';

    #[Assert\Positive]
    public int $boardComingWindowMin = 60;

    #[Assert\Positive]
    public int $boardOffDutyWindowMin = 60;

    #[Assert\Positive]
    public int $boardForecastHorizonHours = 5;

    #[Assert\Positive]
    public int $boardForecastStepHours = 1;

    #[Assert\Positive]
    public int $boardActiveStaffTopN = 8;

    #[Assert\Positive]
    public int $boardPageSizeStaff = 15;

    #[Assert\Positive]
    public int $boardPageSizeShifts = 12;
}
