<?php

namespace App\Entity;

use App\Enum\HelpResponseType;
use App\Repository\HelpCallResponseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's accept/refuse response to a {@see HelpCall}. A refusal
 * suppresses further notifications for the same call. Unique per call/user.
 */
#[ORM\Entity(repositoryClass: HelpCallResponseRepository::class)]
#[ORM\Table(name: 'help_call_responses')]
#[ORM\UniqueConstraint(name: 'uniq_call_user', columns: ['call_id', 'user_id'])]
#[ORM\HasLifecycleCallbacks]
class HelpCallResponse
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: HelpCall::class, inversedBy: 'responses')]
    #[ORM\JoinColumn(name: 'call_id', nullable: false, onDelete: 'CASCADE')]
    private HelpCall $call;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::STRING, length: 8, enumType: HelpResponseType::class)]
    private HelpResponseType $type;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(HelpCall $call, User $user, HelpResponseType $type)
    {
        $this->call = $call;
        $this->user = $user;
        $this->type = $type;
        $call->addResponse($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCall(): HelpCall
    {
        return $this->call;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getType(): HelpResponseType
    {
        return $this->type;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }
}
