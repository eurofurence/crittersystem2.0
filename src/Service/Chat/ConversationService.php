<?php

namespace App\Service\Chat;

use App\Audit\AuditLogger;
use App\Entity\ChatMessage;
use App\Entity\Conversation;
use App\Entity\ConversationParticipant;
use App\Entity\User;
use App\Enum\ConversationType;
use App\Notification\NotificationCategories;
use App\Repository\ConversationParticipantRepository;
use App\Repository\ConversationRepository;
use App\Service\EventConfigStore;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Conversation initiation and messaging. A support conversation is
 * the shared user↔Info Desk channel (with the configured welcome message); a
 * direct conversation may only be started by an Admin, Sub Admin or Info Desk
 * member. Every user always has the Info Desk Team as their default contact.
 */
final class ConversationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConversationRepository $conversations,
        private readonly ConversationParticipantRepository $participants,
        private readonly EventConfigStore $config,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {
    }

    public function enabled(): bool
    {
        return $this->config->getBool(EventConfigStore::KEY_MESSAGES_ENABLED, true);
    }

    /** Whether a body contains a link/URL (restricted content). */
    public function containsLink(?string $body): bool
    {
        return $body !== null && preg_match('~(https?://|www\.)~i', $body) === 1;
    }

    /**
     * Reason the message may not be sent, or null. Only Info Desk / Admins may
     * send links (and images) — a link from anyone else is rejected.
     */
    public function restrictedContentError(?string $body, bool $maySendRestricted): ?string
    {
        if (!$maySendRestricted && $this->containsLink($body)) {
            return 'Only Info Desk and Admins may send links.';
        }

        return null;
    }

    public function editWindowSeconds(): int
    {
        return $this->config->getInt(EventConfigStore::KEY_MESSAGE_EDIT_WINDOW, EventConfigStore::DEFAULT_MESSAGE_EDIT_WINDOW);
    }

    /**
     * Reason the message cannot be edited by this user, or null:
     * only the sender, and only within the edit window.
     */
    public function editMessageError(ChatMessage $message, User $user): ?string
    {
        if ($message->getSender() !== $user) {
            return 'You can only edit your own messages.';
        }
        $deadline = $message->getCreatedAt()->modify(\sprintf('+%d seconds', $this->editWindowSeconds()));
        if (new \DateTimeImmutable() > $deadline) {
            return 'The edit window has closed.';
        }

        return null;
    }

    /**
     * Edit a message within the window. The edit is marked and the
     * change audited for traceability.
     *
     * @throws \RuntimeException on an invalid edit
     */
    public function editMessage(ChatMessage $message, User $user, string $newBody, bool $maySendRestricted): ChatMessage
    {
        if (($error = $this->editMessageError($message, $user)) !== null) {
            throw new \RuntimeException($error);
        }
        if (($error = $this->restrictedContentError($newBody, $maySendRestricted)) !== null) {
            throw new \RuntimeException($error);
        }

        $message->setBody($newBody)->markEdited();
        $this->em->flush();

        $this->audit->log(\App\Audit\AuditEvents::CHAT, \App\Audit\AuditEvents::UPDATE, [
            'resourceType' => 'chat_message', 'resourceId' => (string) $message->getId(),
        ]);

        return $message;
    }

    /** Post an image message (Info Desk / Admins only). */
    public function postImage(Conversation $conversation, User $sender, string $storageKey): ChatMessage
    {
        $message = new ChatMessage($conversation, $sender, null);
        $message->setImagePath($storageKey);
        $conversation->touch();
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    /** Only Admins, Sub Admins and Info Desk may start a direct conversation. */
    public function canInitiateDirect(User $user): bool
    {
        $roles = $user->getRoles();

        return \in_array('ROLE_ADMIN', $roles, true)
            || \in_array('ROLE_SUBADMIN', $roles, true)
            || $user->isInfoDesk();
    }

    /**
     * The user's open support conversation with the Info Desk Team, created with
     * the configured welcome message on first contact.
     */
    public function startSupport(User $user): Conversation
    {
        $conversation = $this->conversations->findOpenSupportForUser($user);
        if ($conversation !== null) {
            return $conversation;
        }

        $conversation = new Conversation(ConversationType::SUPPORT, $user);
        $this->em->persist($conversation);
        $this->em->persist(new ConversationParticipant($conversation, $user));

        $welcome = trim((string) $this->config->get(EventConfigStore::KEY_INFODESK_WELCOME, ''));
        if ($welcome !== '') {
            $this->em->persist(new ChatMessage($conversation, null, $welcome));
        }
        $this->em->flush();

        $this->notifyInfoDeskQueue($conversation, $user);

        return $conversation;
    }

    /**
     * Start (or reuse) a direct conversation from an authorized initiator to a
     * target user.
     *
     * @throws \RuntimeException when the initiator may not start conversations
     */
    public function startDirect(User $initiator, User $target): Conversation
    {
        if (!$this->canInitiateDirect($initiator)) {
            throw new \RuntimeException('You are not allowed to start a conversation.');
        }

        $existing = $this->conversations->findDirectBetween($initiator, $target);
        if ($existing !== null) {
            return $existing;
        }

        $conversation = new Conversation(ConversationType::DIRECT);
        $this->em->persist($conversation);
        $this->em->persist(new ConversationParticipant($conversation, $initiator));
        $this->em->persist(new ConversationParticipant($conversation, $target));
        $this->em->flush();

        return $conversation;
    }

    /** Post a message (or system/internal notice) to a conversation. */
    public function post(Conversation $conversation, ?User $sender, ?string $body, bool $internal = false): ChatMessage
    {
        $message = new ChatMessage($conversation, $sender, $body, $internal);
        $conversation->touch();
        $this->em->persist($message);
        $this->em->flush();

        // A support conversation's user reply re-notifies the Info Desk queue.
        if (!$internal && $sender !== null && $conversation->getType() === ConversationType::SUPPORT
            && $conversation->getSubject() === $sender) {
            $this->notifyInfoDeskQueue($conversation, $sender);
        }

        return $message;
    }

    /**
     * Messages visible to a viewer: internal notices are hidden from the support
     * subject (non-staff) user.
     *
     * @return ChatMessage[]
     */
    public function visibleMessages(Conversation $conversation, User $viewer): array
    {
        $isSubject = $conversation->getSubject() === $viewer;

        return array_values(array_filter(
            $conversation->getMessages()->toArray(),
            static fn (ChatMessage $m) => !$m->isInternal() || !$isSubject,
        ));
    }

    /**
     * Display name for a message sender: Info Desk responders show
     * as "Info Desk - <name>", Admin/Sub Admin responders as "Administrator -
     * <name>", the subject user as their own name, and a system message as the
     * Info Desk Team.
     */
    public function senderLabel(ChatMessage $message): string
    {
        $sender = $message->getSender();
        if ($sender === null) {
            return 'Info Desk Team';
        }
        if ($message->getConversation()->getSubject() === $sender) {
            return $sender->getName();
        }
        $roles = $sender->getRoles();
        if (\in_array('ROLE_ADMIN', $roles, true) || \in_array('ROLE_SUBADMIN', $roles, true)) {
            return 'Administrator - '.$sender->getName();
        }
        if ($sender->isInfoDesk()) {
            return 'Info Desk - '.$sender->getName();
        }

        return $sender->getName();
    }

    public function markRead(Conversation $conversation, User $user): void
    {
        $participant = $this->participants->findOneBy(['conversation' => $conversation, 'user' => $user]);
        if ($participant === null) {
            $participant = new ConversationParticipant($conversation, $user);
            $this->em->persist($participant);
        }
        $participant->markRead();
        $this->em->flush();
    }

    /** Unread message count for a participant. */
    public function unreadCount(Conversation $conversation, User $user): int
    {
        $participant = $this->participants->findOneBy(['conversation' => $conversation, 'user' => $user]);
        $since = $participant?->getLastReadAt();

        $count = 0;
        foreach ($conversation->getMessages() as $message) {
            if ($message->getSender() === $user || $message->isInternal()) {
                continue;
            }
            if ($since === null || $message->getCreatedAt() > $since) {
                ++$count;
            }
        }

        return $count;
    }

    /** Record that a user is typing . */
    public function markTyping(Conversation $conversation, User $user): void
    {
        $participant = $this->participants->findOneBy(['conversation' => $conversation, 'user' => $user]);
        if ($participant === null) {
            $participant = new ConversationParticipant($conversation, $user);
            $this->em->persist($participant);
        }
        $participant->markTyping();
        $this->em->flush();
    }

    /**
     * Names of other participants currently typing.
     *
     * @return string[]
     */
    public function othersTyping(Conversation $conversation, User $viewer): array
    {
        $names = [];
        foreach ($conversation->getParticipants() as $participant) {
            if ($participant->getUser() !== $viewer && $participant->isTyping()) {
                $names[] = $participant->getUser()->getName();
            }
        }

        return $names;
    }

    /** In-app-only notification to the Info Desk queue (never email/Telegram). */
    private function notifyInfoDeskQueue(Conversation $conversation, User $from): void
    {
        // The INFO_DESK category is in-app-only, so this never routes to
        // email/Telegram. This records the queue signal in the subject's own
        // history; fan-out to the Info Desk members is the queue service's job.
        $this->notifications->notify(
            $from,
            NotificationCategories::INFO_DESK,
            'Info Desk',
            'Your message was sent to the Info Desk.',
            '/messages/'.$conversation->getUuid(),
        );
    }
}
