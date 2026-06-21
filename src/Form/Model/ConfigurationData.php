<?php

namespace App\Form\Model;

use App\Service\EventConfigStore;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form-backing model for the global display/regional configuration. The
 * controller maps this to/from the EventConfig key-value store. These settings
 * decide how dates and times are shown to every user, server-side, ignoring the
 * viewer's browser locale and timezone.
 */
final class ConfigurationData
{
    /** IANA timezone identifier, e.g. "Europe/Berlin". */
    #[Assert\NotBlank]
    #[Assert\Timezone]
    public string $timezone = EventConfigStore::DEFAULT_TIMEZONE;

    /** PHP date() format for date-only output. */
    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    public string $dateFormat = EventConfigStore::DEFAULT_DATE_FORMAT;

    /** PHP date() format for time-only output. */
    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    public string $timeFormat = EventConfigStore::DEFAULT_TIME_FORMAT;

    /** PHP date() format for combined date + time output. */
    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    public string $dateTimeFormat = EventConfigStore::DEFAULT_DATETIME_FORMAT;
}
