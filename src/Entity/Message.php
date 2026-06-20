<?php

namespace App\Entity;

use App\Repository\MessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A one-to-one private message between two users
 */
#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: 'messages')]
#[ORM\Index(name: 'idx_message_pair', columns: ['sender_id', 'receiver_id'])]
#[ORM\HasLifecycleCallbacks]
class Message
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'sender_id', nullable: false, onDelete: 'CASCADE')]
    private User $sender;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'receiver_id', nullable: false, onDelete: 'CASCADE')]
    private User $receiver;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $text;

    #[ORM\Column(name: 'is_read')]
    private bool $isRead = false;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $sender, User $receiver, string $text)
    {
        $this->sender = $sender;
        $this->receiver = $receiver;
        $this->text = $text;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSender(): User
    {
        return $this->sender;
    }

    public function getReceiver(): User
    {
        return $this->receiver;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function isRead(): bool
    {
        return $this->isRead;
    }

    public function setIsRead(bool $isRead): static
    {
        $this->isRead = $isRead;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }
}
