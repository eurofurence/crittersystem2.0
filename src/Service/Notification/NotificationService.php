<?php

namespace App\Service\Notification;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\Notification;
use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Mercure\Topics;
use App\Mercure\UpdatePublisher;
use App\Notification\NotificationCategories;
use App\Repository\NotificationPreferenceRepository;
use App\Repository\NotificationRepository;
use App\Service\EventConfigStore;
use App\Service\Notifier;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The single entry point for delivering notifications. In-app is
 * always delivered for system categories and for users who leave it enabled;
 * email and Telegram are delivered only when the user's preference allows and
 * the category is not in-app-only.
 */
class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationRepository $notifications,
        private readonly NotificationPreferenceRepository $preferences,
        private readonly Notifier $notifier,
        private readonly TelegramSender $telegram,
        private readonly EventConfigStore $config,
        private readonly AuditLogger $audit,
        private readonly UpdatePublisher $live,
    ) {
    }

    public function notify(User $user, string $category, string $title, string $message, ?string $actionUrl = null): ?Notification
    {
        if (!NotificationCategories::isValid($category)) {
            $category = NotificationCategories::GENERAL;
        }

        $system = NotificationCategories::isSystem($category);
        $inAppOnly = NotificationCategories::isInAppOnly($category);
        $preference = $this->preferences->findOneForUserCategory($user, $category);

        $created = null;
        if ($system || ($preference?->isInApp() ?? true)) {
            $created = new Notification($user, $category, $title, $message, $actionUrl, $system);
            $this->em->persist($created);
            $this->em->flush();

            // Wake this user's bell. The signal says only that something arrived; the browser
            // re-requests the bell fragment, which is rendered for whoever is actually signed in.
            // Nothing about the notification crosses the hub - not its title, not its category.
            $this->live->signal(Topics::userNotifications($user));
        }

        if (!$inAppOnly) {
            if ($preference?->isEmail() ?? false) {
                $this->notifier->sendTo($user, $title, $message);
            }
            if ($preference?->isTelegram() ?? false) {
                $this->telegram->send($user, $title, $message);
            }
        }

        return $created;
    }

    /**
     * @param iterable<User> $users
     */
    public function notifyAudience(iterable $users, string $category, string $title, string $message, ?string $actionUrl = null): void
    {
        foreach ($users as $user) {
            $this->notify($user, $category, $title, $message, $actionUrl);
        }
    }

    public function unreadCount(User $user): int
    {
        return $this->notifications->countUnread($user);
    }

    /**
     * @return Notification[]
     */
    public function recent(User $user, int $limit = 10): array
    {
        return $this->notifications->findRecent($user, $limit);
    }

    /**
     * @return Notification[]
     */
    public function history(User $user): array
    {
        return $this->notifications->findForUser($user);
    }

    public function markRead(Notification $notification): void
    {
        if (!$notification->isRead()) {
            $notification->markRead();
            $this->em->flush();
        }
    }

    public function markAllRead(User $user): void
    {
        $this->notifications->markAllRead($user, new \DateTimeImmutable());

        // The user may be reading in more than one tab; the count has to drop in all of them.
        $this->live->signal(Topics::userNotifications($user));
    }

    /** Effective shift-reminder lead in minutes: the user's choice, else the system default. */
    public function reminderLeadMinutes(User $user): int
    {
        $userChoice = $user->getSettings()?->getNotificationReminderLead();
        if ($userChoice !== null) {
            return $userChoice;
        }

        return (int) round($this->config->getInt(
            EventConfigStore::KEY_SHIFT_REMINDER_LEAD,
            EventConfigStore::DEFAULT_SHIFT_REMINDER_LEAD,
        ) / 60);
    }

    /**
     * @return array<int, array{category: string, label: string, system: bool, inAppOnly: bool, inApp: bool, email: bool, telegram: bool}>
     */
    public function preferenceMatrix(User $user): array
    {
        $existing = $this->preferences->mapForUser($user);
        $rows = [];
        foreach (NotificationCategories::all() as $category) {
            $preference = $existing[$category] ?? null;
            $system = NotificationCategories::isSystem($category);
            $inAppOnly = NotificationCategories::isInAppOnly($category);
            $rows[] = [
                'category' => $category,
                'label' => NotificationCategories::label($category),
                'system' => $system,
                'inAppOnly' => $inAppOnly,
                'inApp' => $system ? true : ($preference?->isInApp() ?? true),
                'email' => $inAppOnly ? false : ($preference?->isEmail() ?? false),
                'telegram' => $inAppOnly ? false : ($preference?->isTelegram() ?? false),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, array{inApp?: bool, email?: bool, telegram?: bool}> $input
     */
    public function savePreferences(User $user, array $input): void
    {
        $existing = $this->preferences->mapForUser($user);
        foreach (NotificationCategories::all() as $category) {
            $data = $input[$category] ?? [];
            $preference = $existing[$category] ?? new NotificationPreference($user, $category);
            $system = NotificationCategories::isSystem($category);
            $inAppOnly = NotificationCategories::isInAppOnly($category);

            $preference->setInApp($system ? true : (bool) ($data['inApp'] ?? false));
            $preference->setEmail($inAppOnly ? false : (bool) ($data['email'] ?? false));
            $preference->setTelegram($inAppOnly ? false : (bool) ($data['telegram'] ?? false));

            if ($preference->getId() === null) {
                $this->em->persist($preference);
            }
        }
        $this->em->flush();

        $this->audit->log(AuditEvents::NOTIFICATION, AuditEvents::UPDATE, [
            'resourceType' => 'NotificationPreference',
            'resourceId' => $user->getId(),
        ]);
    }
}
