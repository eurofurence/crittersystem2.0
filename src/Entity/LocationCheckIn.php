<?php

namespace App\Entity;

use App\Entity\Concern\HasPublicUuid;
use App\Enum\LocationCheckInAction;
use App\Repository\LocationCheckInRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One entry to, or withdrawal from, the event location, as recorded by the security team.
 *
 * The log is append-only: nothing here is ever updated or deleted, so a withdrawal is a new row and
 * the history of somebody who came and went several times over a setup week survives intact. Who is
 * currently inside is derived from the newest row for a date, which means there is no cached flag
 * that can disagree with the record.
 *
 * This is not the same thing as {@see State::isArrived()}, which is the once-per-event arrival flag
 * used by the backstage desk and set when staff finish onboarding. Arrival happens once; entering
 * the location happens on every day of setup, sometimes twice.
 */
#[ORM\Entity(repositoryClass: LocationCheckInRepository::class)]
#[ORM\Table(name: 'location_check_ins')]
#[ORM\Index(name: 'idx_location_check_in_day', columns: ['local_date'])]
#[ORM\Index(name: 'idx_location_check_in_user', columns: ['user_id', 'local_date'])]
class LocationCheckIn
{
    use HasPublicUuid;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16, enumType: LocationCheckInAction::class)]
    private LocationCheckInAction $action;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    /**
     * The calendar date this counts towards, in the event's own timezone.
     *
     * Stored rather than derived from `occurredAt`. Instants are kept in UTC while the event runs in
     * a configured zone, so grouping the daily counts on the timestamp would split a setup evening
     * across two days and disagree with the day security actually worked.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $localDate;

    /** The security operator who performed this, kept even if they later lose the privilege. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    /** Set only when the operator admitted somebody the eligibility rules refused. */
    #[ORM\Column]
    private bool $overridden = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $overrideReason = null;

    public function __construct(
        User $user,
        LocationCheckInAction $action,
        \DateTimeImmutable $occurredAt,
        \DateTimeImmutable $localDate,
        ?User $actor,
    ) {
        $this->uuid = Uuid::v4();
        $this->user = $user;
        $this->action = $action;
        $this->occurredAt = $occurredAt;
        $this->localDate = $localDate;
        $this->actor = $actor;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getAction(): LocationCheckInAction
    {
        return $this->action;
    }

    public function isEntry(): bool
    {
        return $this->action === LocationCheckInAction::ENTERED;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getLocalDate(): \DateTimeImmutable
    {
        return $this->localDate;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function isOverridden(): bool
    {
        return $this->overridden;
    }

    public function getOverrideReason(): ?string
    {
        return $this->overrideReason;
    }

    /** Records that the eligibility rules refused this entry and the operator admitted anyway. */
    public function markOverridden(string $reason): static
    {
        $this->overridden = true;
        $this->overrideReason = $reason;

        return $this;
    }
}
