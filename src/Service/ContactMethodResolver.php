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
    /**
     * Department id => [user id => true] for departments whose membership has been primed.
     *
     * @var array<int, array<int, true>>
     */
    private array $primedMembership = [];

    public function __construct(
        private readonly Security $security,
        private readonly PiiMasker $pii,
        private readonly UserGroupAssignmentRepository $assignments,
    ) {
    }

    /**
     * Answer the membership half of the contact-visibility check from a set already in memory,
     * instead of one query per subject. Intended for list screens that have just loaded a
     * department's members anyway.
     *
     * The set MUST be the complete active membership of that department, from the same read that
     * decided which rows to show. Priming a set that contains a non-member would hand that
     * non-member's consented contact details to the viewer - an over-broad set fails open, whereas
     * an incomplete one merely hides channels the viewer could have seen.
     *
     * @param int[] $memberIds
     */
    public function primeMembership(Department $department, array $memberIds): void
    {
        $this->primedMembership[$department->getId()] = array_fill_keys($memberIds, true);
    }

    /**
     * The subject themselves and the admin PII override see every channel regardless of consent;
     * everyone else needs both a qualifying relationship ({@see relationshipAllows()}) and the
     * subject's per-channel consent. The internal message is the one channel nobody can withhold, so
     * it is always offered, and always last.
     *
     * @return array<int, array{type: string, label: string, href: ?string}>
     */
    public function methodsFor(User $subject, ?Department $context = null): array
    {
        $methods = [];
        $contact = $subject->getContact();
        $consent = $subject->getConsent();

        $unrestricted = $this->seesEverything($subject);
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
     *
     * `user:contact:view` is Info Desk's grant and reaches consented contacts org-wide. A
     * department or shift manager reaches only members of a department they manage, and both halves
     * are required: the privilege is scoped by passing the Department as the subject, then
     * membership is confirmed separately. Checking the privilege without a subject grants
     * unconditionally, and checking it against the User resolves to no departments and grants too.
     */
    private function relationshipAllows(User $subject, ?Department $context): bool
    {
        if ($this->security->isGranted('user:contact:view')) {
            return true;
        }

        if ($context !== null
            && ($this->security->isGranted('department:manage', $context) || $this->security->isGranted('shift:manage', $context))
            && $this->isMember($subject, $context)
        ) {
            return true;
        }

        return false;
    }

    private function isMember(User $subject, Department $department): bool
    {
        $primed = $this->primedMembership[$department->getId()] ?? null;

        return $primed !== null
            ? isset($primed[$subject->getId()])
            : $this->assignments->userIsMember($subject, $department);
    }
}
