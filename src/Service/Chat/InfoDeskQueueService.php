<?php

namespace App\Service\Chat;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\Conversation;
use App\Entity\User;
use App\Enum\ConversationStatus;
use App\Repository\ConversationRepository;
use App\Service\EventConfigStore;
use App\Service\Shift\ShiftConcurrency;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Info Desk support queue: claiming, timeout release, administrative join and
 * conversation lifecycle. Claiming is concurrency-safe: the
 * conversation row is write-locked so two members cannot claim at once, and an
 * idle claim past the configured timeout is released automatically. All actions
 * are audited.
 */
final class InfoDeskQueueService
{
    private const DEFAULT_TIMEOUT_MINUTES = 5;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConversationRepository $conversations,
        private readonly ShiftConcurrency $concurrency,
        private readonly ConversationService $chat,
        private readonly EventConfigStore $config,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return Conversation[] */
    public function waiting(): array
    {
        $result = [];
        foreach ($this->conversations->findWaitingSupport() as $conversation) {
            $result[] = $conversation;
        }

        return $result;
    }

    /** @return Conversation[] */
    public function claimedBy(User $owner): array
    {
        return $this->conversations->findClaimedBy($owner);
    }

    public function timeoutMinutes(): int
    {
        return $this->config->getInt(EventConfigStore::KEY_INFODESK_CLAIM_TIMEOUT, self::DEFAULT_TIMEOUT_MINUTES);
    }

    /** Release a claim that has been idle past the timeout. */
    public function releaseIfTimedOut(Conversation $conversation): bool
    {
        if (!$conversation->isClaimed() || $conversation->getClaimedAt() === null) {
            return false;
        }
        $deadline = $conversation->getClaimedAt()->modify(\sprintf('+%d minutes', $this->timeoutMinutes()));
        if (new \DateTimeImmutable() < $deadline) {
            return false;
        }
        $conversation->releaseClaim();
        $this->em->flush();
        $this->chat->signalChanged($conversation);

        return true;
    }

    /**
     * Claim a support conversation. Exclusive (Admin/Sub Admin)
     * claims block Info Desk claims. Concurrency-safe via a row lock.
     *
     * @throws \RuntimeException when already claimed by someone else
     */
    public function claim(Conversation $conversation, User $owner, bool $exclusive): Conversation
    {
        return $this->concurrency->transactional(function () use ($conversation, $owner, $exclusive): Conversation {
            $this->concurrency->lockForUpdate($conversation);
            $this->releaseIfTimedOut($conversation);

            if ($conversation->isClaimed() && $conversation->getClaimedBy() !== $owner) {
                throw new \RuntimeException('This conversation is already claimed by '.$conversation->getClaimedBy()->getName().'.');
            }

            $conversation->claim($owner, $exclusive);
            $this->em->flush();

            // Everyone reading the thread sees the header change, and the other responders need
            // their queue to stop offering a conversation that is now taken.
            $this->chat->signalChanged($conversation);

            $this->audit->log(AuditEvents::CHAT, AuditEvents::CLAIM, [
                'resourceType' => 'conversation', 'resourceId' => (string) $conversation->getId(),
            ]);

            return $conversation;
        });
    }

    public function unclaim(Conversation $conversation, User $owner): void
    {
        if ($conversation->getClaimedBy() !== $owner) {
            return;
        }
        $conversation->releaseClaim();
        $this->em->flush();
        $this->chat->signalChanged($conversation);
        $this->audit->log(AuditEvents::CHAT, AuditEvents::UNCLAIM, [
            'resourceType' => 'conversation', 'resourceId' => (string) $conversation->getId(),
        ]);
    }

    /**
     * An Admin/Sub Admin joins a conversation. The current owner
     * gets an internal notice; the join is audited. The frontend confirms first.
     */
    public function join(Conversation $conversation, User $admin): void
    {
        $this->chat->post($conversation, null, \sprintf('%s joined the conversation.', $admin->getName()), internal: true);
        $this->audit->log(AuditEvents::CHAT, AuditEvents::JOIN, [
            'resourceType' => 'conversation', 'resourceId' => (string) $conversation->getId(),
        ]);
    }

    /** Close/finalize a conversation with the optional finalization message. */
    public function close(Conversation $conversation, User $actor): void
    {
        $finalization = trim((string) $this->config->get(EventConfigStore::KEY_INFODESK_FINALIZATION, ''));
        if ($finalization !== '') {
            $this->chat->post($conversation, null, $finalization);
        }
        $conversation->setStatus(ConversationStatus::CLOSED)->releaseClaim();
        $this->em->flush();
        $this->chat->signalChanged($conversation);
        $this->audit->log(AuditEvents::CHAT, AuditEvents::CLOSE, [
            'resourceType' => 'conversation', 'resourceId' => (string) $conversation->getId(),
        ]);
    }

    public function reopen(Conversation $conversation): void
    {
        $conversation->setStatus(ConversationStatus::OPEN);
        $this->em->flush();
        $this->chat->signalChanged($conversation);
        $this->audit->log(AuditEvents::CHAT, AuditEvents::REOPEN, [
            'resourceType' => 'conversation', 'resourceId' => (string) $conversation->getId(),
        ]);
    }

    /** Owner display label. */
    public function ownerLabel(Conversation $conversation): ?string
    {
        $owner = $conversation->getClaimedBy();
        if ($owner === null) {
            return null;
        }
        $prefix = $conversation->isExclusiveClaim() ? 'Administrator' : 'Info Desk';

        return $prefix.' - '.$owner->getName();
    }
}
