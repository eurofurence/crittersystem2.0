<?php

namespace App\Entity;

use App\Enum\ConversationStatus;
use App\Enum\ConversationType;
use App\Repository\ConversationRepository;
use App\Entity\Concern\HasPublicUuid;
use Symfony\Component\Uid\Uuid;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A support or direct conversation. A support conversation is
 * between a subject user and the Info Desk Team; an Info Desk / Admin member
 * claims it before replying. The claim is optimistically versioned so
 * two members cannot claim it at once.
 */
#[ORM\Entity(repositoryClass: ConversationRepository::class)]
#[ORM\Table(name: 'conversations')]
#[ORM\HasLifecycleCallbacks]
class Conversation
{
    use HasPublicUuid;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: ConversationType::class)]
    private ConversationType $type;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: ConversationStatus::class)]
    private ConversationStatus $status = ConversationStatus::OPEN;

    /** The non-staff user a support conversation is for. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'subject_id', nullable: true, onDelete: 'CASCADE')]
    private ?User $subject = null;

    /** The Info Desk / Admin member who owns the conversation. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'claimed_by', nullable: true, onDelete: 'SET NULL')]
    private ?User $claimedBy = null;

    #[ORM\Column(name: 'claimed_at', nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    /** True when the owner is an Admin/Sub Admin exclusive claim. */
    #[ORM\Column(name: 'exclusive_claim')]
    private bool $exclusiveClaim = false;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, ChatMessage> */
    #[ORM\OneToMany(mappedBy: 'conversation', targetEntity: ChatMessage::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $messages;

    /** @var Collection<int, ConversationParticipant> */
    #[ORM\OneToMany(mappedBy: 'conversation', targetEntity: ConversationParticipant::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $participants;

    public function __construct(ConversationType $type, ?User $subject = null)
    {
        $this->uuid = Uuid::v4();
        $this->type = $type;
        $this->subject = $subject;
        $this->messages = new ArrayCollection();
        $this->participants = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): ConversationType
    {
        return $this->type;
    }

    public function getStatus(): ConversationStatus
    {
        return $this->status;
    }

    public function setStatus(ConversationStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isOpen(): bool
    {
        return $this->status === ConversationStatus::OPEN;
    }

    public function getSubject(): ?User
    {
        return $this->subject;
    }

    public function getClaimedBy(): ?User
    {
        return $this->claimedBy;
    }

    public function getClaimedAt(): ?\DateTimeImmutable
    {
        return $this->claimedAt;
    }

    public function isClaimed(): bool
    {
        return $this->claimedBy !== null;
    }

    public function claim(User $owner, bool $exclusive): static
    {
        $this->claimedBy = $owner;
        $this->claimedAt = new \DateTimeImmutable();
        $this->exclusiveClaim = $exclusive;

        return $this;
    }

    public function releaseClaim(): static
    {
        $this->claimedBy = null;
        $this->claimedAt = null;
        $this->exclusiveClaim = false;

        return $this;
    }

    public function isExclusiveClaim(): bool
    {
        return $this->exclusiveClaim;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** @return Collection<int, ChatMessage> */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(ChatMessage $message): static
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
        }

        return $this;
    }

    /** @return Collection<int, ConversationParticipant> */
    public function getParticipants(): Collection
    {
        return $this->participants;
    }

    public function addParticipant(ConversationParticipant $participant): static
    {
        if (!$this->participants->contains($participant)) {
            $this->participants->add($participant);
        }

        return $this;
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function onSave(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt ??= $now;
        $this->updatedAt = $now;
    }
}
