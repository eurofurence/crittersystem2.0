<?php

namespace App\Entity;

use App\Enum\RequestLinkType;
use App\Repository\RequestLinkRepository;
use App\Entity\Concern\HasPublicUuid;
use Symfony\Component\Uid\Uuid;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A department-bound, login-required link: either an Availability
 * Request or a Shift Application Invitation. Expirable and revocable, with a
 * response deadline; after the deadline an availability request is read-only
 * unless an authorized manager reopens it. Creation/revocation/use are audited.
 */
#[ORM\Entity(repositoryClass: RequestLinkRepository::class)]
#[ORM\Table(name: 'request_links')]
#[ORM\HasLifecycleCallbacks]
class RequestLink
{
    use HasPublicUuid;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $token;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: RequestLinkType::class)]
    private RequestLinkType $type;

    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'department_id', nullable: false, onDelete: 'CASCADE')]
    private Department $department;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /** Response deadline / expiry; null means no deadline. */
    #[ORM\Column(name: 'expires_at', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'revoked_at', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(RequestLinkType $type, Department $department, string $token, ?User $createdBy = null, ?\DateTimeImmutable $expiresAt = null)
    {
        $this->uuid = Uuid::v4();
        $this->type = $type;
        $this->department = $department;
        $this->token = $token;
        $this->createdBy = $createdBy;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getType(): RequestLinkType
    {
        return $this->type;
    }

    public function getDepartment(): Department
    {
        return $this->department;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(): void
    {
        $this->revokedAt ??= new \DateTimeImmutable();
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt !== null && ($now ?? new \DateTimeImmutable()) >= $this->expiresAt;
    }

    /** Usable for access: not revoked and not past its deadline. */
    public function isActive(?\DateTimeImmutable $now = null): bool
    {
        return !$this->isRevoked() && !$this->isExpired($now);
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }
}
