<?php

namespace App\Service;

use App\Entity\Department;
use App\Entity\User;
use App\Repository\UserGroupAssignmentRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Resolves the ways the current viewer may contact a subject, in priority order:
 * Telegram bot, Telegram handle, email, phone, and finally the always-available
 * internal message.
 *
 * Each entry is: ['type' => string, 'label' => string, 'href' => ?string].
 * A null href (e.g. internal message) is resolved to a route by the template.
 */
class ContactMethodResolver
{
    public function __construct(
        private readonly Security $security,
        private readonly PiiMasker $pii,
        private readonly UserGroupAssignmentRepository $assignments,
    ) {
    }

    /**
     * @return array<int, array{type: string, label: string, href: ?string}>
     */
    public function methodsFor(User $subject, ?Department $context = null): array
    {
        $methods = [];
        $contact = $subject->getContact();
        $consent = $subject->getConsent();

        // Self and the admin override see every channel regardless of consent.
        $unrestricted = $this->seesEverything($subject);
        // The consent-gated relationship: a manager of the subject's department, or Info Desk.
        $related = $unrestricted || $this->relationshipAllows($subject, $context);

        $telegramShared = $unrestricted || ($related && (bool) $consent?->isTelegramVisible());
        if ($subject->isTelegramLinked() && $telegramShared) {
            $methods[] = ['type' => 'telegram_bot', 'label' => 'Telegram bot message', 'href' => null];
        }

        $handle = $subject->getTelegramHandle();
        if ($handle !== null && $handle !== '' && $telegramShared) {
            $methods[] = ['type' => 'telegram', 'label' => '@'.ltrim($handle, '@'), 'href' => 'https://t.me/'.ltrim($handle, '@')];
        }

        $email = $contact?->getEmail() ?: $subject->getEmail();
        if ($email !== '' && ($unrestricted || ($related && (bool) $consent?->isEmailVisible()))) {
            $methods[] = ['type' => 'email', 'label' => $email, 'href' => 'mailto:'.$email];
        }

        $mobile = $contact?->getMobile();
        if ($mobile !== null && $mobile !== '' && ($unrestricted || ($related && (bool) $consent?->isPhoneVisible()))) {
            $methods[] = ['type' => 'phone', 'label' => $mobile, 'href' => 'tel:'.preg_replace('/[^0-9+]/', '', $mobile)];
        }

        // Internal message is always available and always last.
        $methods[] = ['type' => 'message', 'label' => 'Internal message', 'href' => null];

        return $methods;
    }

    /**
     * Same model as the contact channels.
     */
    public function fullNameFor(User $subject, ?Department $context = null): ?string
    {
        $pd = $subject->getPersonalData();
        $full = trim(($pd?->getFirstName() ?? '').' '.($pd?->getLastName() ?? ''));
        if ($full === '') {
            return null;
        }
        if ($this->seesEverything($subject)) {
            return $full;
        }

        return ($this->relationshipAllows($subject, $context) && (bool) $subject->getConsent()?->isFullNameVisible())
            ? $full
            : null;
    }

    /** Self, or the admin PII override (user:pii:view + fresh step-up). */
    private function seesEverything(User $subject): bool
    {
        $viewer = $this->security->getUser();
        if ($viewer instanceof User && $viewer->getId() === $subject->getId()) {
            return true;
        }

        return $this->pii->canSeePii();
    }

    /**
     * Whether the viewer stands in a relationship that, given the subject's
     * consent, entitles them to the subject's contact channels.
     */
    private function relationshipAllows(User $subject, ?Department $context): bool
    {
        // Info Desk sees consented contacts org-wide.
        if ($this->security->isGranted('user:contact:view')) {
            return true;
        }

        // A department/shift manager sees consented contacts of members of a
        // department they manage. Scope by the Department (safe), then confirm the
        // subject is actually a member of it.
        if ($context !== null
            && ($this->security->isGranted('department:manage', $context) || $this->security->isGranted('shift:manage', $context))
            && $this->assignments->userIsMember($subject, $context)
        ) {
            return true;
        }

        return false;
    }
}
