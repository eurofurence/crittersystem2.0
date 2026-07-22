<?php

namespace App\Twig;

use App\Entity\User;
use App\Service\OperationalStatusService;
use Psr\Log\LoggerInterface;
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
        private readonly LoggerInterface $logger,
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

        // Rendered by the shared layout on every page, error pages included. If
        // the status lookup fails (e.g. the database is what broke), the widget
        // must degrade to hidden rather than break the error page itself.
        try {
            return $this->status->viewModel($user);
        } catch (\Throwable $e) {
            $this->logger->warning('Operational status widget unavailable: {reason}', [
                'reason' => $e->getMessage(),
                'exception' => $e,
            ]);

            return null;
        }
    }
}
