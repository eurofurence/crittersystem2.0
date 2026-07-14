<?php

namespace App\Service\Notification;

use App\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * Best-effort Telegram notification delivery.
 *
 * The outbound bot send is not implemented: this only records the attempt for
 * linked users and reports that nothing was actually delivered. In-app and email
 * are the reliable channels.
 */
final class TelegramSender
{
    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
    }

    public function send(User $user, string $title, string $message): bool
    {
        if (!$user->isTelegramLinked()) {
            return false;
        }

        $this->logger?->info('Telegram notification queued (delivery not yet implemented)', [
            'user' => $user->getId(),
            'title' => $title,
        ]);

        return false;
    }
}
