<?php

declare(strict_types=1);

namespace App\Command;

use App\Backup\BackupRetention;
use App\Backup\DatabaseDumper;
use App\Backup\S3BackupStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Dumps the database with pg_dump and uploads it to the backup bucket, then
 * prunes dumps older than the retention window.
 *
 * The destination is configured by its OWN parameter group (BACKUP_S3_*),
 * separate from the app's storage DSNs, so the backup bucket can carry a
 * different, write-scoped policy - see deploy/k8s/backup-secret.example.yaml.
 * Only after a dump uploads and is confirmed present does pruning run, so a
 * failed dump can never delete the backups it was meant to replace.
 *
 * Must run on a schedule - cron, or the CronJob in deploy/k8s/backup.yaml.
 */
#[AsCommand(
    name: 'app:backup:database',
    description: 'Dump the database to the backup S3 bucket and prune old dumps.',
)]
final class BackupDatabaseCommand extends Command
{
    public function __construct(private readonly DatabaseDumper $dumper)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $bucket = $this->env('BACKUP_S3_BUCKET');
        if ($bucket === '') {
            $io->error('BACKUP_S3_BUCKET is not set; nothing to back up to.');

            return Command::FAILURE;
        }

        $store = new S3BackupStore(
            endpoint: $this->env('BACKUP_S3_ENDPOINT'),
            region: $this->env('BACKUP_S3_REGION'),
            bucket: $bucket,
            prefix: $this->env('BACKUP_S3_PREFIX'),
            pathStyle: filter_var($this->env('BACKUP_S3_PATH_STYLE'), \FILTER_VALIDATE_BOOL),
            accessKeyId: $this->env('BACKUP_S3_ACCESS_KEY_ID'),
            secretAccessKey: $this->env('BACKUP_S3_SECRET_ACCESS_KEY'),
        );

        $dumpFile = (string) tempnam(sys_get_temp_dir(), 'critter-db-');
        $key = 'critter-' . gmdate('Ymd-His') . '.dump';

        try {
            $this->dumper->dumpTo($dumpFile);

            $stream = fopen($dumpFile, 'rb');
            if ($stream === false) {
                $io->error('Could not open the dump file for upload.');

                return Command::FAILURE;
            }
            try {
                $store->put($key, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if (!$store->exists($key)) {
                $io->error(sprintf('Upload of "%s" reported success but the object is not present.', $key));

                return Command::FAILURE;
            }
            $io->success(sprintf('Uploaded %s (%s).', $key, $this->humanBytes((int) filesize($dumpFile))));
        } finally {
            if (is_file($dumpFile)) {
                @unlink($dumpFile);
            }
        }

        // Pruning runs only after a confirmed upload above, so a failed backup
        // never removes the older dumps that are still the last good copy.
        $retentionDays = (int) ($this->env('BACKUP_RETENTION_DAYS') ?: '14');
        if ($retentionDays < 1) {
            $io->warning('BACKUP_RETENTION_DAYS < 1 - retention disabled, keeping all dumps.');

            return Command::SUCCESS;
        }

        $cutoff = (new \DateTimeImmutable('now'))->modify(sprintf('-%d days', $retentionDays));
        $pruned = 0;
        foreach (BackupRetention::expired($store->entries(), $cutoff) as $expiredKey) {
            $store->delete($expiredKey);
            ++$pruned;
        }
        $io->writeln(sprintf('Pruned %d dump(s) older than %d day(s).', $pruned, $retentionDays));

        return Command::SUCCESS;
    }

    private function env(string $key): string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return is_string($value) ? trim($value) : '';
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            ++$i;
        }

        return sprintf('%.1f %s', $size, $units[$i]);
    }
}
