<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PrivacyNotice;
use App\Repository\PrivacyNoticeRepository;

/**
 * Substitutes %placeholder variables in consent and privacy texts using the
 * event configuration and the current privacy notice (event name, data
 * controller, contact email, deletion period).
 */
final class TextVariables
{
    public function __construct(
        private readonly EventConfigStore $config,
        private readonly PrivacyNoticeRepository $privacyNotices,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function map(?PrivacyNotice $notice = null): array
    {
        $notice ??= $this->privacyNotices->current();

        return [
            '%event_name' => (string) $this->config->get(EventConfigStore::KEY_NAME, 'the event'),
            '%organization' => $notice?->getControllerOrg() ?? '',
            '%controller_email' => $notice?->getControllerEmail() ?? '',
            '%contact_email' => $notice?->getContactEmail() ?? '',
            '%deletion_days' => (string) ($notice?->getDeletionDays() ?? 60),
        ];
    }

    public function apply(string $text, ?PrivacyNotice $notice = null): string
    {
        return strtr($text, $this->map($notice));
    }
}
