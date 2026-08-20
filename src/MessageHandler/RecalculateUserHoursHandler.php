<?php

namespace App\MessageHandler;

use App\Message\RecalculateUserHours;
use App\Repository\UserRepository;
use App\Service\HoursCacheService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Recalculates one user's hours off the request path.
 *
 * A user who has been deleted between the sweep finding them and the worker reaching them is not an
 * error: the work is simply no longer needed, and throwing would send the message to the failure
 * transport for something nobody can act on.
 */
#[AsMessageHandler]
final readonly class RecalculateUserHoursHandler
{
    public function __construct(
        private UserRepository $users,
        private HoursCacheService $hours,
    ) {
    }

    public function __invoke(RecalculateUserHours $message): void
    {
        $user = $this->users->find($message->userId);
        if ($user === null) {
            return;
        }

        $this->hours->recalculate($user);
    }
}
