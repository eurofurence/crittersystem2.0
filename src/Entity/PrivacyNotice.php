<?php

namespace App\Entity;

use App\Repository\PrivacyNoticeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The site privacy notice, stored in the database so admins can edit it, with a
 * shipped default template available for one-click restore. The body is rich
 * text and supports variables (event name, controller, contact, deletion days).
 */
#[ORM\Entity(repositoryClass: PrivacyNoticeRepository::class)]
#[ORM\Table(name: 'privacy_notices')]
#[ORM\HasLifecycleCallbacks]
class PrivacyNotice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'event_name', length: 255)]
    private string $eventName = '';

    #[ORM\Column(name: 'controller_org', length: 255)]
    private string $controllerOrg = '';

    #[ORM\Column(name: 'controller_email', length: 255)]
    private string $controllerEmail = '';

    #[ORM\Column(name: 'contact_email', length: 255)]
    private string $contactEmail = '';

    #[ORM\Column(name: 'deletion_days')]
    private int $deletionDays = 60;

    #[ORM\Column(name: 'body_html', type: 'text')]
    private string $bodyHtml = '';

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEventName(): string
    {
        return $this->eventName;
    }

    public function setEventName(string $v): static
    {
        $this->eventName = $v;

        return $this;
    }

    public function getControllerOrg(): string
    {
        return $this->controllerOrg;
    }

    public function setControllerOrg(string $v): static
    {
        $this->controllerOrg = $v;

        return $this;
    }

    public function getControllerEmail(): string
    {
        return $this->controllerEmail;
    }

    public function setControllerEmail(string $v): static
    {
        $this->controllerEmail = $v;

        return $this;
    }

    public function getContactEmail(): string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(string $v): static
    {
        $this->contactEmail = $v;

        return $this;
    }

    public function getDeletionDays(): int
    {
        return $this->deletionDays;
    }

    public function setDeletionDays(int $v): static
    {
        $this->deletionDays = $v;

        return $this;
    }

    public function getBodyHtml(): string
    {
        return $this->bodyHtml;
    }

    public function setBodyHtml(string $v): static
    {
        $this->bodyHtml = $v;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
