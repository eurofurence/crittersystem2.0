<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 24, unique: true)]
    private string $name;

    #[ORM\Column(length: 254, unique: true)]
    private string $email;

    #[ORM\Column(length: 255)]
    private string $password;

    #[ORM\Column(name: 'api_key', length: 32, unique: true)]
    private string $apiKey;

    #[ORM\Column(name: 'last_login_at', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: PersonalData::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?PersonalData $personalData = null;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: Contact::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?Contact $contact = null;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: Settings::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?Settings $settings = null;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: State::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?State $state = null;

    /** @var Collection<int, Group> */
    #[ORM\ManyToMany(targetEntity: Group::class, inversedBy: 'users')]
    #[ORM\JoinTable(name: 'users_groups')]
    private Collection $groups;

    public function __construct()
    {
        $this->groups = new ArrayCollection();
    }

    /**
     * Keep the session payload small and free of the relation graph; the hashed
     * password is still stored so that changing it invalidates existing sessions.
     */
    public function __serialize(): array
    {
        return ['id' => $this->id, 'name' => $this->name ?? null, 'password' => $this->password ?? null];
    }

    public function __unserialize(array $data): void
    {
        $this->id = $data['id'] ?? null;
        $this->name = $data['name'] ?? '';
        $this->password = $data['password'] ?? '';
        $this->groups = new ArrayCollection();
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

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function setApiKey(string $apiKey): static
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt ??= $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getPersonalData(): ?PersonalData
    {
        return $this->personalData;
    }

    public function setPersonalData(?PersonalData $personalData): static
    {
        if ($personalData !== null && $personalData->getUser() !== $this) {
            $personalData->setUser($this);
        }
        $this->personalData = $personalData;

        return $this;
    }

    public function getContact(): ?Contact
    {
        return $this->contact;
    }

    public function setContact(?Contact $contact): static
    {
        if ($contact !== null && $contact->getUser() !== $this) {
            $contact->setUser($this);
        }
        $this->contact = $contact;

        return $this;
    }

    public function getSettings(): ?Settings
    {
        return $this->settings;
    }

    public function setSettings(?Settings $settings): static
    {
        if ($settings !== null && $settings->getUser() !== $this) {
            $settings->setUser($this);
        }
        $this->settings = $settings;

        return $this;
    }

    public function getState(): ?State
    {
        return $this->state;
    }

    public function setState(?State $state): static
    {
        if ($state !== null && $state->getUser() !== $this) {
            $state->setUser($this);
        }
        $this->state = $state;

        return $this;
    }

    /** @return Collection<int, Group> */
    public function getGroups(): Collection
    {
        return $this->groups;
    }

    public function addGroup(Group $group): static
    {
        if (!$this->groups->contains($group)) {
            $this->groups->add($group);
        }

        return $this;
    }

    public function removeGroup(Group $group): static
    {
        $this->groups->removeElement($group);

        return $this;
    }

    /**
     * Effective privilege names: the union of all privileges across the user's groups.
     *
     * @return string[]
     */
    public function getPrivilegeNames(): array
    {
        $names = [];
        foreach ($this->groups as $group) {
            foreach ($group->getPrivileges() as $privilege) {
                $names[$privilege->getName()] = true;
            }
        }

        return array_keys($names);
    }

    public function hasPrivilege(string $name): bool
    {
        return \in_array($name, $this->getPrivilegeNames(), true);
    }

    /** @param string[] $names */
    public function hasAnyPrivilege(array $names): bool
    {
        return [] !== array_intersect($names, $this->getPrivilegeNames());
    }

    /**
     * Coarse roles derived from privileges for firewall/access_control gating.
     * Fine-grained checks go through the privilege voter, not getRoles().
     *
     * @return string[]
     */
    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];
        if ($this->hasPrivilege('admin') || $this->hasPrivilege('user.type.admin')) {
            $roles[] = 'ROLE_ADMIN';
        }
        if ($this->hasAnyPrivilege(['user.type.staff', 'user.type.internal_staff', 'user.type.admin'])) {
            $roles[] = 'ROLE_STAFF';
        }

        return array_values(array_unique($roles));
    }

    public function getUserIdentifier(): string
    {
        return $this->name;
    }
}
