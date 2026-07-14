<?php

namespace App\Entity;

use App\Repository\ConversationParticipantRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's membership of a conversation with per-user read tracking (unread
 * state/count).
 */
#[ORM\Entity(repositoryClass: ConversationParticipantRepository::class)]
#[ORM\Table(name: 'conversation_participants')]
#[ORM\UniqueConstraint(name: 'uniq_conv_user', columns: ['conversation_id', 'user_id'])]
class ConversationParticipant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Conversation::class, inversedBy: 'participants')]
    #[ORM\JoinColumn(name: 'conversation_id', nullable: false, onDelete: 'CASCADE')]
    private Conversation $conversation;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'last_read_at', nullable: true)]
    private ?\DateTimeImmutable $lastReadAt = null;

    /** When this participant last signalled they were typing. */
    #[ORM\Column(name: 'typing_at', nullable: true)]
    private ?\DateTimeImmutable $typingAt = null;

    public function __construct(Conversation $conversation, User $user)
    {
        $this->conversation = $conversation;
        $this->user = $user;
        $conversation->addParticipant($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getLastReadAt(): ?\DateTimeImmutable
    {
        return $this->lastReadAt;
    }

    public function markRead(): static
    {
        $this->lastReadAt = new \DateTimeImmutable();

        return $this;
    }

    public function getTypingAt(): ?\DateTimeImmutable
    {
        return $this->typingAt;
    }

    public function markTyping(): static
    {
        $this->typingAt = new \DateTimeImmutable();

        return $this;
    }

    /** True when the participant signalled typing within the last few seconds. */
    public function isTyping(): bool
    {
        return $this->typingAt !== null
            && $this->typingAt > new \DateTimeImmutable('-6 seconds');
    }
}
