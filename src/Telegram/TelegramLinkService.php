<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\TelegramLinkRequest;
use App\Entity\User;
use App\Repository\TelegramLinkRequestRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Manages Telegram account linking. The actual exchange with the companion bot
 * server is implemented later; {@see confirm()} is the entry point the bot (or
 * the dev dummy bot) calls once it has verified the user holds the code.
 */
final class TelegramLinkService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TelegramLinkRequestRepository $requests,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Begin a link: invalidate any pending request and issue a fresh code. */
    public function startLink(User $user): TelegramLinkRequest
    {
        foreach ($this->requests->findBy(['user' => $user, 'status' => TelegramLinkRequest::STATUS_PENDING]) as $pending) {
            $pending->markExpired();
        }

        $request = new TelegramLinkRequest($user, strtoupper(bin2hex(random_bytes(6))));
        $this->em->persist($request);
        $this->em->flush();

        $this->audit->log(AuditEvents::USER_MANAGEMENT, AuditEvents::UPDATE, ['details' => ['telegram' => 'link_started']]);

        return $request;
    }

    /** Confirm a link from the bot. Returns the linked user, or null if invalid. */
    public function confirm(string $code, string $telegramId, ?string $handle): ?User
    {
        $request = $this->requests->findOneByCode($code);
        if ($request === null || !$request->isPending()) {
            return null;
        }
        if ($request->isExpired()) {
            $request->markExpired();
            $this->em->flush();

            return null;
        }

        $user = $request->getUser();
        $user->linkTelegram($telegramId, $handle);
        $request->markLinked();
        $this->em->flush();

        $this->audit->log(AuditEvents::USER_MANAGEMENT, AuditEvents::UPDATE, [
            'resourceType' => 'User',
            'resourceId' => $user->getId(),
            'details' => ['telegram' => 'linked'],
        ]);

        return $user;
    }

    public function unlink(User $user, ?User $actor = null): void
    {
        $user->unlinkTelegram();
        $this->em->flush();

        // The bot link is revoked through the companion API in a later phase.
        $this->audit->log(AuditEvents::USER_MANAGEMENT, AuditEvents::UPDATE, [
            'resourceType' => 'User',
            'resourceId' => $user->getId(),
            'details' => ['telegram' => 'unlinked', 'by' => $actor?->getUserIdentifier() ?? 'self'],
        ]);
    }

    public function pendingFor(User $user): ?TelegramLinkRequest
    {
        return $this->requests->findPendingForUser($user);
    }
}
