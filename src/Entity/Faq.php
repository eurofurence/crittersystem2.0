<?php

namespace App\Entity;

use App\Repository\FaqRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Admin-curated FAQ entry, grouped by category and publicly visible
 */
#[ORM\Entity(repositoryClass: FaqRepository::class)]
#[ORM\Table(name: 'faqs')]
class Faq
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 128)]
    private string $category = 'General';

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $question;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $answer;

    #[ORM\Column(name: 'display_order')]
    private int $displayOrder = 0;

    public function __construct(string $question = '', string $answer = '')
    {
        $this->question = $question;
        $this->answer = $answer;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getQuestion(): string
    {
        return $this->question;
    }

    public function setQuestion(string $question): static
    {
        $this->question = $question;

        return $this;
    }

    public function getAnswer(): string
    {
        return $this->answer;
    }

    public function setAnswer(string $answer): static
    {
        $this->answer = $answer;

        return $this;
    }

    public function getDisplayOrder(): int
    {
        return $this->displayOrder;
    }

    public function setDisplayOrder(int $displayOrder): static
    {
        $this->displayOrder = $displayOrder;

        return $this;
    }
}
