<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\RecalculateUserHours;
use App\Repository\UserHoursCacheRepository;
use App\Repository\UserRepository;
use App\Service\HoursCacheService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Refreshes the cached hours of everybody whose hours could have changed.
 *
 * The cache exists because recomputing a breakdown per request is expensive, but nothing kept it
 * current: a shift ending writes nothing at all, and a worklog or a no-show used to leave the row
 * untouched. Volunteers therefore saw hours that only moved when somebody pressed a button.
 *
 * A run selects users rather than recalculating everybody: whoever holds a shift that ended after
 * their row was last calculated, plus whoever was flagged when their data changed. Both are states
 * rather than events, so a missed run costs nothing and the next one catches up.
 *
 * Work is dispatched to the worker one user at a time, so a large sweep does not run inside the cron
 * process. `--sync` does it inline instead, for an operator watching the output or an install with
 * no worker running.
 *
 * Must run on a schedule: cron, the compose service, or the CronJob in the k8s manifests.
 */
#[AsCommand(
    name: 'app:hours:recalculate',
    description: 'Recalculate cached volunteer hours for everybody whose hours could have changed.',
)]
final class RecalculateHoursCommand extends Command
{
    public function __construct(
        private readonly UserHoursCacheRepository $caches,
        private readonly UserRepository $users,
        private readonly HoursCacheService $hours,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('all', null, InputOption::VALUE_NONE, 'Recalculate every user with hours, ignoring what changed.')
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Recalculate inline instead of dispatching to the worker.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Process at most this many users in one run.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rawLimit = $input->getOption('limit');
        $limit = $rawLimit === null ? null : max(1, (int) $rawLimit);

        $userIds = $input->getOption('all')
            ? $this->caches->findAllUserIdsWithHours()
            : $this->caches->findUserIdsNeedingRecalculation($limit);

        if ($input->getOption('all') && $limit !== null) {
            $userIds = \array_slice($userIds, 0, $limit);
        }

        if ($userIds === []) {
            $io->success('Every cached total is already current.');

            return Command::SUCCESS;
        }

        if ($input->getOption('sync')) {
            foreach ($userIds as $userId) {
                $user = $this->users->find($userId);
                if ($user !== null) {
                    $this->hours->recalculate($user);
                }
            }
            $io->success(sprintf('Recalculated %d user(s).', \count($userIds)));

            return Command::SUCCESS;
        }

        foreach ($userIds as $userId) {
            $this->bus->dispatch(new RecalculateUserHours($userId));
        }

        $io->success(sprintf('Queued %d user(s) for recalculation.', \count($userIds)));

        return Command::SUCCESS;
    }
}
