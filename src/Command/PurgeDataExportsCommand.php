<?php

declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Repository\DataExportRepository;
use App\Storage\ExportStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes GDPR data-export archives once their download window has closed.
 *
 * The archive is a complete copy of one user's personal data. The record declares a 24-hour TTL and
 * stops offering the download, but that alone leaves the file in storage indefinitely - data
 * minimisation requires it to actually be deleted. The export record itself is kept (the request and
 * its expiry are auditable); only the archive goes.
 *
 * Dropping the storage key off the record is what makes the purge idempotent: the next run no longer
 * sees the record.
 *
 * Must run on a schedule - cron, or the CronJob in deploy/k8s/app.yaml.
 */
#[AsCommand(
    name: 'app:gdpr:purge-exports',
    description: 'Remove GDPR data-export archives past their download window.',
)]
final class PurgeDataExportsCommand extends Command
{
    public function __construct(
        private readonly DataExportRepository $exports,
        private readonly ExportStorage $storage,
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

        foreach ($this->exports->findExpiredWithArchive($now) as $export) {
            $key = (string) $export->getStorageKey();
            if ($this->storage->exists($key)) {
                $this->storage->delete($key);
                ++$purged;
            }

            $export->forgetArchive();

            $this->audit->system(AuditEvents::DATA_EXPORT, AuditEvents::EXPIRE, [
                'resourceType' => 'DataExport',
                'resourceId' => $export->getUuid(),
                'details' => ['expired_at' => $export->getExpiresAt()->format(\DATE_ATOM)],
            ]);
        }

        $this->em->flush();
        $io->success(sprintf('Purged %d expired data-export archive(s).', $purged));

        return Command::SUCCESS;
    }
}
