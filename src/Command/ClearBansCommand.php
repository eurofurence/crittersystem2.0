<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BannedIdentity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Clears expired bans. A ban is held until the next event or for 90 days from
 * the ban date, whichever comes first. This command enforces the 90-day rule
 * (run it on a schedule); the per-event reset is performed when a new event is
 * configured.
 */
#[AsCommand(name: 'app:bans:clear', description: 'Remove bans older than the 90-day retention window.')]
final class ClearBansCommand extends Command
{
    private const MAX_AGE_DAYS = 90;

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cutoff = new \DateTimeImmutable('-'.self::MAX_AGE_DAYS.' days');

        $removed = $this->em->createQueryBuilder()
            ->delete(BannedIdentity::class, 'b')
            ->where('b.bannedAt <= :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();

        $io->success(\sprintf('Cleared %d expired ban(s).', (int) $removed));

        return Command::SUCCESS;
    }
}
