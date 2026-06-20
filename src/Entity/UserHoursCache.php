<?php

namespace App\Entity;

use App\Repository\UserHoursCacheRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cached, denormalised hours breakdown per user used by the goodies
 * eligibility checks so the dashboard and distribution screens don't recompute
 * from scratch on every request. Refreshed by {@see \App\Service\HoursCacheService}.
 */
#[ORM\Entity(repositoryClass: UserHoursCacheRepository::class)]
#[ORM\Table(name: 'user_hours_cache')]
class UserHoursCache
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'total_hours')]
    private float $totalHours = 0.0;

    #[ORM\Column(name: 'day_shifts_hours')]
    private float $dayShiftsHours = 0.0;

    #[ORM\Column(name: 'night_shifts_hours')]
    private float $nightShiftsHours = 0.0;

    #[ORM\Column(name: 'noshow_penalty_hours')]
    private float $noshowPenaltyHours = 0.0;

    #[ORM\Column(name: 'worklog_hours')]
    private float $worklogHours = 0.0;

    #[ORM\Column(name: 'completed_shifts_count')]
    private int $completedShiftsCount = 0;

    #[ORM\Column(name: 'night_shifts_count')]
    private int $nightShiftsCount = 0;

    #[ORM\Column(name: 'noshow_shifts_count')]
    private int $noshowShiftsCount = 0;

    #[ORM\Column(name: 'last_calculated_at')]
    private \DateTimeImmutable $lastCalculatedAt;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->lastCalculatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTotalHours(): float
    {
        return $this->totalHours;
    }

    public function setTotalHours(float $totalHours): static
    {
        $this->totalHours = $totalHours;

        return $this;
    }

    public function getDayShiftsHours(): float
    {
        return $this->dayShiftsHours;
    }

    public function setDayShiftsHours(float $dayShiftsHours): static
    {
        $this->dayShiftsHours = $dayShiftsHours;

        return $this;
    }

    public function getNightShiftsHours(): float
    {
        return $this->nightShiftsHours;
    }

    public function setNightShiftsHours(float $nightShiftsHours): static
    {
        $this->nightShiftsHours = $nightShiftsHours;

        return $this;
    }

    public function getNoshowPenaltyHours(): float
    {
        return $this->noshowPenaltyHours;
    }

    public function setNoshowPenaltyHours(float $noshowPenaltyHours): static
    {
        $this->noshowPenaltyHours = $noshowPenaltyHours;

        return $this;
    }

    public function getWorklogHours(): float
    {
        return $this->worklogHours;
    }

    public function setWorklogHours(float $worklogHours): static
    {
        $this->worklogHours = $worklogHours;

        return $this;
    }

    public function getCompletedShiftsCount(): int
    {
        return $this->completedShiftsCount;
    }

    public function setCompletedShiftsCount(int $completedShiftsCount): static
    {
        $this->completedShiftsCount = $completedShiftsCount;

        return $this;
    }

    public function getNightShiftsCount(): int
    {
        return $this->nightShiftsCount;
    }

    public function setNightShiftsCount(int $nightShiftsCount): static
    {
        $this->nightShiftsCount = $nightShiftsCount;

        return $this;
    }

    public function getNoshowShiftsCount(): int
    {
        return $this->noshowShiftsCount;
    }

    public function setNoshowShiftsCount(int $noshowShiftsCount): static
    {
        $this->noshowShiftsCount = $noshowShiftsCount;

        return $this;
    }

    public function getLastCalculatedAt(): \DateTimeImmutable
    {
        return $this->lastCalculatedAt;
    }

    public function setLastCalculatedAt(\DateTimeImmutable $lastCalculatedAt): static
    {
        $this->lastCalculatedAt = $lastCalculatedAt;

        return $this;
    }
}
