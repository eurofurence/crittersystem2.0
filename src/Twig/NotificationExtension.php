<?php

namespace App\Twig;

use App\Entity\User;
use App\Service\Notification\NotificationService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes {@see notification_bell()} so the navbar bell renders inline without a
 * controller round-trip (the fragment is then re-fetched on a signal).
 */
final class NotificationExtension extends AbstractExtension
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('notification_bell', $this->bell(...)),
        ];
    }

    /**
     * Rendered by the shared layout on every page, error pages included, which are shown precisely
     * when something (often the database) has failed. A failing lookup must hide the bell rather than
     * turn the friendly error page back into a 500.
     *
     * @return array{count: int, recent: array<int, \App\Entity\Notification>}|null
     */
    public function bell(): ?array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        try {
            return [
                'count' => $this->notifications->unreadCount($user),
                'recent' => $this->notifications->recent($user, 8),
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Notification bell unavailable: {reason}', [
                'reason' => $e->getMessage(),
                'exception' => $e,
            ]);

            return null;
        }
    }
}
