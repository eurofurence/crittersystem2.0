<?php

namespace App\Service;

use App\Entity\User;

/**
 * Resolves the permitted ways to contact a user, in the order required by
 * priority order: Telegram bot, Telegram handle, email, phone, and finally the
 * always-available internal message. Only methods the subject has consented to
 * share are returned.
 *
 * Each entry is: ['type' => string, 'label' => string, 'href' => ?string].
 * A null href (e.g. internal message) is resolved to a route by the template.
 */
class ContactMethodResolver
{
    /**
     * @return array<int, array{type: string, label: string, href: ?string}>
     */
    public function methodsFor(User $subject): array
    {
        $methods = [];
        $settings = $subject->getSettings();
        $contact = $subject->getContact();

        if ($subject->isTelegramLinked()) {
            $methods[] = ['type' => 'telegram_bot', 'label' => 'Telegram bot message', 'href' => null];
        }

        $handle = $subject->getTelegramHandle();
        if ($handle !== null && $handle !== '') {
            $methods[] = ['type' => 'telegram', 'label' => '@'.ltrim($handle, '@'), 'href' => 'https://t.me/'.ltrim($handle, '@')];
        }

        $email = $contact?->getEmail() ?: $subject->getEmail();
        if ($email !== '') {
            $methods[] = ['type' => 'email', 'label' => $email, 'href' => 'mailto:'.$email];
        }

        $mobile = $contact?->getMobile();
        if ($mobile !== null && $mobile !== '' && ($settings?->isMobileShow() ?? false)) {
            $methods[] = ['type' => 'phone', 'label' => $mobile, 'href' => 'tel:'.preg_replace('/[^0-9+]/', '', $mobile)];
        }

        // Internal message is always available and always last.
        $methods[] = ['type' => 'message', 'label' => 'Internal message', 'href' => null];

        return $methods;
    }
}
