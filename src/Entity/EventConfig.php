<?php

namespace App\Entity;

use App\Repository\EventConfigRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Key-value store for global event configuration. Values are stored as
 * JSON so a single row can hold scalars, lists, or structured settings.
 */
#[ORM\Entity(repositoryClass: EventConfigRepository::class)]
#[ORM\Table(name: 'event_config')]
class EventConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'config_key', length: 128, unique: true)]
    private string $key;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private mixed $value = null;

    public function __construct(string $key, mixed $value = null)
    {
        $this->key = $key;
        $this->value = $value;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): static
    {
        $this->key = $key;

        return $this;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function setValue(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }
}
