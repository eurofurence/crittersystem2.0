<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\StaffCheckInService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Checks in the staff who completed onboarding before check-in became part of finishing it, so they
 * can apply to shifts. Each user is stamped with their own onboarding completion time.
 *
 * Safe to run repeatedly and on live data: it only ever turns a missing check-in on, and never
 * touches a user the Info Desk has already checked in.
 */
#[AsCommand(
    name: 'app:onboarding:checkin-staff',
    description: 'Check in staff who completed onboarding but were never marked arrived.',
)]
final class CheckInOnboardedStaffCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly StaffCheckInService $checkIn,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $changed = [];
        foreach ($this->users->findOnboardedNotCheckedIn() as $user) {
            if ($this->checkIn->checkInOnboardedStaff($user)) {
                $changed[] = [$user->getUserIdentifier(), $user->getOnboardingCompletedAt()?->format('Y-m-d H:i')];
            }
        }

        if ($dryRun) {
            $this->em->clear();
            $io->table(['User', 'Arrival date it would record'], $changed);
            $io->note(\sprintf('%d staff account(s) would be checked in. Nothing was written.', \count($changed)));

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(\sprintf('Checked in %d staff account(s).', \count($changed)));

        return Command::SUCCESS;
    }
}
