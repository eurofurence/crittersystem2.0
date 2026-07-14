<?php

namespace App\Entity;

use App\Repository\BadgeRepository;
use App\Entity\Concern\HasPublicUuid;
use Symfony\Component\Uid\Uuid;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An identification badge shown on the Digital ID and in user management.
 *
 *  - "position" badges (BoD, Director, Staff, Volunteer) are mutually ranked by
 *    priority; a user's highest-priority position badge is the one displayed.
 *  - "standard" badges (Medical Support, Security, ...) are non-ranked tags used
 *    to quickly identify members.
 *
 * Every badge has a slug usable as an SSO-group reference.
 */
#[ORM\Entity(repositoryClass: BadgeRepository::class)]
#[ORM\Table(name: 'badges')]
class Badge
{
    use HasPublicUuid;

    public const TYPE_POSITION = 'position';
    public const TYPE_STANDARD = 'standard';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $name;

    #[ORM\Column(length: 64, unique: true)]
    private string $slug;

    #[ORM\Column(length: 16)]
    private string $type = self::TYPE_STANDARD;

    /** Higher wins when resolving a user's displayed position badge. */
    #[ORM\Column]
    private int $priority = 0;

    /** Tabler colour token used for the badge pill (e.g. "red", "azure"). */
    #[ORM\Column(length: 32)]
    private string $color = 'secondary';

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class, mappedBy: 'badges')]
    private Collection $users;

    public function __construct(string $name = '', string $slug = '', string $type = self::TYPE_STANDARD)
    {
        $this->uuid = Uuid::v4();
        $this->name = $name;
        $this->slug = $slug;
        $this->type = $type;
        $this->users = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function isPosition(): bool
    {
        return $this->type === self::TYPE_POSITION;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    /** @return Collection<int, User> */
    public function getUsers(): Collection
    {
        return $this->users;
    }
}
