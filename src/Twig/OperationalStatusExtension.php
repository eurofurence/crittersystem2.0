<?php

namespace App\Twig;

use App\Entity\User;
use App\Service\OperationalStatusService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes {@see operational_status()} so the navbar widget can render the
 * current user's effective operational status without a controller round-trip.
 */
final class OperationalStatusExtension extends AbstractExtension
{
    public function __construct(
        private readonly OperationalStatusService $status,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('operational_status', $this->currentStatus(...)),
        ];
    }

    /**
     * @return array{value: string, label: string, freeToHelp: bool, expiresAt: ?\DateTimeImmutable, durations: int[]}|null
     */
    public function currentStatus(): ?array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        return $this->status->viewModel($user);
    }
}
