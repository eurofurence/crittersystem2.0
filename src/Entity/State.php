<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'users_state')]
class State
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'state', targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private bool $arrived = false;

    #[ORM\Column(name: 'arrival_date', nullable: true)]
    private ?\DateTimeImmutable $arrivalDate = null;

    #[ORM\Column(name: 'user_info', type: Types::TEXT, nullable: true)]
    private ?string $userInfo = null;

    #[ORM\Column]
    private bool $active = false;

    #[ORM\Column(name: 'force_active')]
    private bool $forceActive = false;

    #[ORM\Column(name: 'got_goodie')]
    private bool $gotGoodie = false;

    #[ORM\Column(name: 'got_voucher')]
    private int $gotVoucher = 0;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function isArrived(): bool
    {
        return $this->arrived;
    }

    public function setArrived(bool $arrived): static
    {
        $this->arrived = $arrived;

        return $this;
    }

    public function getArrivalDate(): ?\DateTimeImmutable
    {
        return $this->arrivalDate;
    }

    public function setArrivalDate(?\DateTimeImmutable $arrivalDate): static
    {
        $this->arrivalDate = $arrivalDate;

        return $this;
    }

    public function getUserInfo(): ?string
    {
        return $this->userInfo;
    }

    public function setUserInfo(?string $userInfo): static
    {
        $this->userInfo = $userInfo;

        return $this;
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

    public function isForceActive(): bool
    {
        return $this->forceActive;
    }

    public function setForceActive(bool $forceActive): static
    {
        $this->forceActive = $forceActive;

        return $this;
    }

    public function isGotGoodie(): bool
    {
        return $this->gotGoodie;
    }

    public function setGotGoodie(bool $gotGoodie): static
    {
        $this->gotGoodie = $gotGoodie;

        return $this;
    }

    public function getGotVoucher(): int
    {
        return $this->gotVoucher;
    }

    public function setGotVoucher(int $gotVoucher): static
    {
        $this->gotVoucher = $gotVoucher;

        return $this;
    }
}
