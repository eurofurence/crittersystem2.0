<?php

namespace App\Twig;

use App\Entity\User;
use App\Service\Notification\NotificationService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes {@see notification_bell()} so the navbar bell renders inline without a
 * controller round-trip (the fragment is then polled by live-refresh).
 */
final class NotificationExtension extends AbstractExtension
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('notification_bell', $this->bell(...)),
        ];
    }

    /**
     * @return array{count: int, recent: array<int, \App\Entity\Notification>}|null
     */
    public function bell(): ?array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        return [
            'count' => $this->notifications->unreadCount($user),
            'recent' => $this->notifications->recent($user, 8),
        ];
    }
}
