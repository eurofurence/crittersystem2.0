<?php

declare(strict_types=1);

namespace App\Command;

use App\Backup\BackupRetention;
use App\Backup\BackupStoreFactory;
use App\Backup\DatabaseRestorer;
use App\Backup\DevImportSanitizer;
use App\Backup\DevStatePreserver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Replaces this installation's database with a production dump so changes can
 * be tried against real data. Destructive by design: everything currently in
 * the local database is dropped.
 *
 * Two things survive, and both are deliberate:
 *
 *  - the local administrator's password, which differs from production's on
 *    purpose and is never regenerated;
 *  - the settings naming this environment's identity provider and bot.
 *
 * Everything reachable outward - Telegram links, queued messages, one-time
 * links, 2FA secrets that this instance's key cannot open - is neutralised
 * before the command returns. See {@see DevImportSanitizer} and
 * {@see DevStatePreserver}, and docs/dev-db-import.md.
 */
#[AsCommand(
    name: 'app:db:import-prod',
    description: 'Replace this database with a production dump (destructive, never for production).',
)]
final class ImportProductionDumpCommand extends Command
{
    public function __construct(
        private readonly DatabaseRestorer $restorer,
        private readonly DevImportSanitizer $sanitizer,
        private readonly DevStatePreserver $preserver,
        private readonly BackupStoreFactory $stores,
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to a pg_dump custom-format dump')
            ->addOption('from-s3', null, InputOption::VALUE_NONE, 'Download the dump from the BACKUP_S3_* bucket')
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'Object key to download; defaults to the newest dump')
            ->addOption('admin', null, InputOption::VALUE_REQUIRED, 'Username or email of the local administrator', 'admin')
            ->addOption('grant-admin', null, InputOption::VALUE_NONE, 'Also give that account global admin rights after the import')
            ->addOption('no-migrate', null, InputOption::VALUE_NONE, 'Leave the imported schema as it is instead of migrating it up')
            ->addOption('keep-dump', null, InputOption::VALUE_NONE, 'Keep a downloaded dump instead of deleting it afterwards')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Do not ask for confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->environment === 'prod') {
            $io->error('This command destroys the database it runs against and refuses to run in the prod environment.');

            return Command::FAILURE;
        }

        $file = (string) ($input->getOption('file') ?? '');
        $fromS3 = (bool) $input->getOption('from-s3');
        if (($file === '') === ($fromS3 === false)) {
            $io->error('Give exactly one of --file=<path> or --from-s3.');

            return Command::FAILURE;
        }

        // Checked before anything is downloaded or dropped: a client the server
        // cannot work with, found later, would leave an empty database behind.
        $problem = $this->restorer->clientProblem();
        if ($problem !== null) {
            $io->error([$problem, 'Use ./bin/import-prod-db, which runs this inside the app container.']);

            return Command::FAILURE;
        }

        $downloaded = false;
        if ($fromS3) {
            $file = $this->download($io, $input->getOption('key') !== null ? (string) $input->getOption('key') : null);
            if ($file === null) {
                return Command::FAILURE;
            }
            $downloaded = true;
        }

        if (!is_file($file)) {
            $io->error(sprintf('No such dump file: %s', $file));

            return Command::FAILURE;
        }

        $adminIdentifier = (string) $input->getOption('admin');
        $snapshot = $this->preserver->capture($adminIdentifier);
        if ($snapshot->admin === null) {
            $io->warning(sprintf(
                'No local account matches "%s". Nothing to carry over, so you may have to set a password with app:user:password afterwards.',
                $adminIdentifier,
            ));
        }

        $io->definitionList(
            ['Target' => $this->restorer->target()],
            ['Dump' => sprintf('%s (%s)', $file, $this->humanBytes((int) filesize($file)))],
            ['Keeping' => $snapshot->admin === null ? 'local settings' : sprintf('the password of "%s" and local settings', $snapshot->admin['name'])],
        );

        if (!$input->getOption('force') && !$io->confirm('Everything in the target database will be destroyed. Continue?', false)) {
            $this->cleanUp($file, $downloaded, (bool) $input->getOption('keep-dump'));
            $io->writeln('Aborted; nothing was changed.');

            return Command::SUCCESS;
        }

        try {
            $io->section('Restoring');
            $this->restorer->wipe();
            $this->restorer->restore($file);
            $io->writeln('Dump restored.');

            $migrated = true;
            if (!$input->getOption('no-migrate')) {
                $io->section('Migrating');
                $migrate = $this->getApplication()?->find('app:migrate');
                $migrated = ($migrate?->run(new ArrayInput([]), $output) ?? Command::SUCCESS) === Command::SUCCESS;
            }

            // Runs even when the migration failed. Skipping it would leave a
            // database full of live production data that can still reach the
            // people in it, and that nobody can log into.
            $io->section('Making it safe to run locally');
            foreach ($this->sanitizer->sanitize() as $note) {
                $io->writeln(' - ' . $note);
            }
            foreach ($this->preserver->reapply($snapshot, (bool) $input->getOption('grant-admin')) as $note) {
                $io->writeln(' - ' . $note);
            }

            if (!$migrated) {
                $io->error('The data is in and safe to run against, but the migrations failed: the schema is still production\'s.');

                return Command::FAILURE;
            }
        } finally {
            $this->cleanUp($file, $downloaded, (bool) $input->getOption('keep-dump'));
        }

        $io->success('Import complete.');
        $io->writeln('Uploaded files are not part of the dump, so avatars and exports are gone; everything else is production data - treat it accordingly.');

        return Command::SUCCESS;
    }

    private function download(SymfonyStyle $io, ?string $key): ?string
    {
        if ($this->stores->bucket() === '') {
            $io->error('BACKUP_S3_BUCKET is not set; nothing to download from.');

            return null;
        }

        $store = $this->stores->create();

        if ($key === null) {
            $latest = BackupRetention::latest($store->entries());
            if ($latest === null) {
                $io->error('The bucket holds no dump named like a backup (critter-YYYYMMDD-HHMMSS.dump).');

                return null;
            }
            $key = $latest['key'];
            $io->writeln(sprintf(
                'Newest of %d dump(s): %s%s',
                $latest['count'],
                $key,
                $latest['at'] !== null ? ' from ' . $latest['at']->format('Y-m-d H:i:s T') : '',
            ));
        } elseif (!$store->exists($key)) {
            $io->error(sprintf('No such object in the backup bucket: %s', $key));

            return null;
        }

        $file = (string) tempnam(sys_get_temp_dir(), 'critter-import-');
        $io->writeln(sprintf('Downloading to %s ...', $file));
        $store->readTo($key, $file);

        return $file;
    }

    private function cleanUp(string $file, bool $downloaded, bool $keep): void
    {
        if ($downloaded && !$keep && is_file($file)) {
            @unlink($file);
        }
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
