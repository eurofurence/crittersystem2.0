<?php

namespace App\Entity;

use App\Enum\HelpCallStatus;
use App\Repository\HelpCallRepository;
use App\Entity\Concern\HasPublicUuid;
use Symfony\Component\Uid\Uuid;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Global Call for Help for a shift: a caller requests a number of
 * open slots; filling them closes the call. The version guards the transactional
 * acceptance race.
 */
#[ORM\Entity(repositoryClass: HelpCallRepository::class)]
#[ORM\Table(name: 'help_calls')]
#[ORM\HasLifecycleCallbacks]
class HelpCall
{
    use HasPublicUuid;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Shift::class)]
    #[ORM\JoinColumn(name: 'shift_id', nullable: false, onDelete: 'CASCADE')]
    private Shift $shift;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'slots_requested', type: Types::INTEGER)]
    private int $slotsRequested;

    /** Slots already filled through this call. */
    #[ORM\Column(name: 'slots_filled', type: Types::INTEGER)]
    private int $slotsFilled = 0;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: HelpCallStatus::class)]
    private HelpCallStatus $status = HelpCallStatus::OPEN;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, HelpCallResponse> */
    #[ORM\OneToMany(mappedBy: 'call', targetEntity: HelpCallResponse::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $responses;

    public function __construct(Shift $shift, ?User $createdBy, int $slotsRequested)
    {
        $this->uuid = Uuid::v4();
        $this->shift = $shift;
        $this->createdBy = $createdBy;
        $this->slotsRequested = max(1, $slotsRequested);
        $this->responses = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShift(): Shift
    {
        return $this->shift;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getSlotsRequested(): int
    {
        return $this->slotsRequested;
    }

    public function getSlotsFilled(): int
    {
        return $this->slotsFilled;
    }

    public function slotsRemaining(): int
    {
        return max(0, $this->slotsRequested - $this->slotsFilled);
    }

    public function recordFill(): void
    {
        ++$this->slotsFilled;
        if ($this->slotsRemaining() === 0) {
            $this->status = HelpCallStatus::FILLED;
        }
    }

    public function getStatus(): HelpCallStatus
    {
        return $this->status;
    }

    public function setStatus(HelpCallStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, HelpCallResponse> */
    public function getResponses(): Collection
    {
        return $this->responses;
    }

    public function addResponse(HelpCallResponse $response): static
    {
        if (!$this->responses->contains($response)) {
            $this->responses->add($response);
        }

        return $this;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }
}
