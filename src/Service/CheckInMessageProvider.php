<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Where a volunteer has to go to be checked in, in the language they are reading the site in.
 *
 * The text is admin-editable rather than translated in the catalogs because it names a physical desk
 * at one particular venue, which changes with every event.
 *
 * Any locale other than German - including Klingon, which the UI also offers - reads the English
 * text, and so does German if that field was left empty: a volunteer who is blocked from applying
 * must always be told where to go, and a blank banner would tell them nothing.
 */
final class CheckInMessageProvider
{
    public function __construct(
        private readonly EventConfigStore $config,
        private readonly RequestStack $requests,
    ) {
    }

    public function message(?string $locale = null): string
    {
        $locale ??= $this->requests->getCurrentRequest()?->getLocale() ?? 'en';

        if (str_starts_with($locale, 'de')) {
            $german = trim($this->config->getString(
                EventConfigStore::KEY_CHECKIN_MESSAGE_DE,
                EventConfigStore::DEFAULT_CHECKIN_MESSAGE_DE,
            ));
            if ($german !== '') {
                return $german;
            }
        }

        $english = trim($this->config->getString(
            EventConfigStore::KEY_CHECKIN_MESSAGE_EN,
            EventConfigStore::DEFAULT_CHECKIN_MESSAGE_EN,
        ));

        return $english !== '' ? $english : EventConfigStore::DEFAULT_CHECKIN_MESSAGE_EN;
    }
}
