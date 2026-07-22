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
 * controller round-trip (the fragment is then polled by live-refresh).
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
     * @return array{count: int, recent: array<int, \App\Entity\Notification>}|null
     */
    public function bell(): ?array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        // Rendered on every page via the shared layout - including the error
        // pages, which are shown precisely when something (often the database)
        // has failed. A broken bell must hide itself, not turn the friendly
        // error page back into a 500.
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
