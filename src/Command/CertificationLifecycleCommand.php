<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\CertificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Warns holders whose certification is about to run out, and records the ones that already have.
 * Intended to run daily.
 *
 * Nothing on screen waits for this: every page works out what a record counts as today, so a night
 * the job does not run shows the same thing it always would. What it carries is the telling - an
 * expiry passes at an instant nobody is watching, and a volunteer who is not warned turns up to a
 * shift still believing they are qualified.
 *
 * Safe to run twice in a day: an expiry is only written once because the record stops being held
 * afterwards, and a warning is only sent once per validity period because the record remembers it.
 */
#[AsCommand(
    name: 'app:certifications:lifecycle',
    description: 'Warn about expiring certifications and mark the expired ones.',
)]
final class CertificationLifecycleCommand extends Command
{
    public function __construct(private readonly CertificationService $service)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'warn-days',
                null,
                InputOption::VALUE_REQUIRED,
                'How far ahead of an expiry to warn the holder.',
                (string) CertificationService::EXPIRY_WARNING_DAYS,
            )
            ->addOption('no-warn', null, InputOption::VALUE_NONE, 'Only mark expired records, send no warnings.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $expired = $this->service->markExpired();
        $io->writeln(\sprintf('Marked %d certification(s) as expired.', $expired));

        if ($input->getOption('no-warn')) {
            return Command::SUCCESS;
        }

        $days = max(1, (int) $input->getOption('warn-days'));
        $warned = $this->service->remindExpiring($days);
        $io->writeln(\sprintf('Warned %d holder(s) about an expiry within %d day(s).', $warned, $days));

        return Command::SUCCESS;
    }
}
