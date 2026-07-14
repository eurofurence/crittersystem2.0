<?php

namespace App\Entity;

use App\Repository\ConsentTextRepository;
use App\Entity\Concern\HasPublicUuid;
use Symfony\Component\Uid\Uuid;
use Doctrine\ORM\Mapping as ORM;

/**
 * The translatable consent disclaimer shown during onboarding, split into the
 * header title, header body, the checkbox label and the footer. One row per
 * locale; the body supports variables (e.g. %event_name) substituted at render.
 */
#[ORM\Entity(repositoryClass: ConsentTextRepository::class)]
#[ORM\Table(name: 'consent_texts')]
#[ORM\HasLifecycleCallbacks]
class ConsentText
{
    use HasPublicUuid;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10, unique: true)]
    private string $locale;

    #[ORM\Column(name: 'header_title', length: 255)]
    private string $headerTitle = '';

    #[ORM\Column(name: 'header_body', type: 'text')]
    private string $headerBody = '';

    #[ORM\Column(name: 'checkbox_label', type: 'text')]
    private string $checkboxLabel = '';

    #[ORM\Column(type: 'text')]
    private string $footer = '';

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $locale = 'en_US')
    {
        $this->uuid = Uuid::v4();
        $this->locale = $locale;
    }

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

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getHeaderTitle(): string
    {
        return $this->headerTitle;
    }

    public function setHeaderTitle(string $v): static
    {
        $this->headerTitle = $v;

        return $this;
    }

    public function getHeaderBody(): string
    {
        return $this->headerBody;
    }

    public function setHeaderBody(string $v): static
    {
        $this->headerBody = $v;

        return $this;
    }

    public function getCheckboxLabel(): string
    {
        return $this->checkboxLabel;
    }

    public function setCheckboxLabel(string $v): static
    {
        $this->checkboxLabel = $v;

        return $this;
    }

    public function getFooter(): string
    {
        return $this->footer;
    }

    public function setFooter(string $v): static
    {
        $this->footer = $v;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
