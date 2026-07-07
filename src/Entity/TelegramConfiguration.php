<?php

namespace App\Entity;

use App\Repository\TelegramConfigurationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Site-wide Telegram bot configuration. The API key is encrypted at rest. The
 * companion bot server is developed separately; this stores how to reach it.
 */
#[ORM\Entity(repositoryClass: TelegramConfigurationRepository::class)]
#[ORM\Table(name: 'telegram_configuration')]
#[ORM\HasLifecycleCallbacks]
class TelegramConfiguration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private bool $enabled = false;

    #[ORM\Column(name: 'api_endpoint', length: 255)]
    private string $apiEndpoint = '';

    #[ORM\Column(name: 'api_key', type: 'encrypted_string', nullable: true)]
    private ?string $apiKey = null;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): static { $this->enabled = $enabled; return $this; }
    public function getApiEndpoint(): string { return $this->apiEndpoint; }
    public function setApiEndpoint(string $apiEndpoint): static { $this->apiEndpoint = $apiEndpoint; return $this; }
    public function getApiKey(): ?string { return $this->apiKey; }
    public function setApiKey(?string $apiKey): static { $this->apiKey = $apiKey; return $this; }
    public function hasApiKey(): bool { return $this->apiKey !== null && $this->apiKey !== ''; }
}
