<?php

declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Repository\AuditExportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes legal-export packages whose retention window has elapsed. The export
 * record is kept, its file removed, and the expiry written to the audit log.
 * Intended to run on a schedule (cron / k8s CronJob).
 */
#[AsCommand(
    name: 'app:audit:purge-exports',
    description: 'Remove audit export files past their retention window.',
)]
final class PurgeAuditExportsCommand extends Command
{
    public function __construct(
        private readonly AuditExportRepository $exports,
        private readonly AuditLogger $audit,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();
        $purged = 0;

        foreach ($this->exports->findExpired($now) as $export) {
            $path = $export->getFilePath();
            if (is_file($path)) {
                @unlink($path);
                ++$purged;
            }

            $this->audit->system(AuditEvents::DATA_EXPORT, AuditEvents::EXPIRE, [
                'resourceType' => 'AuditExport',
                'resourceId' => $export->getUuid(),
                'details' => ['expired_at' => $export->getExpiresAt()->format(\DATE_ATOM)],
            ]);
        }

        $this->em->flush();
        $io->success(\sprintf('Purged %d expired export file(s).', $purged));

        return Command::SUCCESS;
    }
}
