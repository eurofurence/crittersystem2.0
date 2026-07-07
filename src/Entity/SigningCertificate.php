<?php

namespace App\Entity;

use App\Repository\SigningCertificateRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The application's audit-signing certificate and key, stored encrypted at rest
 * (the certificate may run inside an ephemeral container). The private key is
 * never written in clear text; both PEMs use the encrypted column type.
 */
#[ORM\Entity(repositoryClass: SigningCertificateRepository::class)]
#[ORM\Table(name: 'signing_certificates')]
#[ORM\HasLifecycleCallbacks]
class SigningCertificate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'certificate_pem', type: 'encrypted_string')]
    private string $certificatePem;

    #[ORM\Column(name: 'private_key_pem', type: 'encrypted_string')]
    private string $privateKeyPem;

    #[ORM\Column(length: 95)]
    private string $fingerprint;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $certificatePem, string $privateKeyPem, string $fingerprint)
    {
        $this->certificatePem = $certificatePem;
        $this->privateKeyPem = $privateKeyPem;
        $this->fingerprint = $fingerprint;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCertificatePem(): string
    {
        return $this->certificatePem;
    }

    public function getPrivateKeyPem(): string
    {
        return $this->privateKeyPem;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
