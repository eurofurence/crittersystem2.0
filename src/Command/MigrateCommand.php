<?php

namespace App\Command;

use App\Console\TeeOutput;
use App\Service\Install\MigrationInspector;
use App\Service\Install\MigrationLock;
use App\Service\Install\InstallStateStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Safe, self-healing database migration entrypoint shared by every deployment
 * path (container entrypoint, k8s initContainer, and the web install wizard).
 *
 * It wraps Doctrine's own migrate command with two guarantees the bare command
 * does not provide on its own:
 *
 *  1. A PostgreSQL advisory lock ({@see MigrationLock}) so concurrent replicas
 *     serialise around a single migrator and a killed pod never leaves a stale
 *     lock behind.
 *  2. all-or-nothing migrations (configured in doctrine_migrations.yaml), so an
 *     interrupted run rolls back as one transaction instead of leaving the
 *     schema half-migrated.
 *
 * Output is streamed to `var/install/migration.log` ({@see InstallStateStore})
 * so the wizard can show live progress, and the result is recorded so the gate
 * and wizard can react.
 */
#[AsCommand(
    name: 'app:migrate',
    description: 'Run pending database migrations safely (advisory lock + all-or-nothing).',
)]
final class MigrateCommand extends Command
{
    public function __construct(
        private readonly MigrationLock $lock,
        private readonly MigrationInspector $inspector,
        private readonly InstallStateStore $state,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'no-wait',
                null,
                InputOption::VALUE_NONE,
                'Exit immediately (success) if another process is already migrating, instead of waiting for the lock.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->inspector->isDatabaseReachable()) {
            $io->error('Database is not reachable. Check DATABASE_URL / that the database is up, then retry.');

            return Command::FAILURE;
        }

        // Already up to date? Nothing to do - this is the common steady-state
        // call from a restarting replica.
        if ($this->inspector->pendingMigrationCount() === 0) {
            $io->success('Database schema is already up to date.');
            $this->state->markReady($this->inspector->latestAvailableVersion());

            return Command::SUCCESS;
        }

        if ($input->getOption('no-wait')) {
            if (!$this->lock->tryAcquire()) {
                $io->warning('Another migration is already in progress; nothing to do.');

                return Command::SUCCESS;
            }
        } else {
            $io->writeln('<comment>Waiting for migration lock…</comment>');
            $this->lock->acquire();
        }

        try {
            // Re-check under the lock: a process we waited for may have already
            // applied everything.
            if ($this->inspector->pendingMigrationCount() === 0) {
                $io->success('Database schema is already up to date.');
                $this->state->markReady($this->inspector->latestAvailableVersion());

                return Command::SUCCESS;
            }

            $this->state->beginMigration();
            $code = $this->runDoctrineMigrate($output);
            $ok = $code === Command::SUCCESS && $this->inspector->pendingMigrationCount() === 0;

            $this->state->finishMigration($ok, $ok ? null : 'Migration command exited with a non-zero status.');

            if ($ok) {
                $this->state->markReady($this->inspector->latestAvailableVersion());
                $io->success('Migrations applied successfully.');

                return Command::SUCCESS;
            }

            $io->error('Migration did not complete successfully. See var/install/migration.log for details.');

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $this->state->appendLog(\PHP_EOL . 'ERROR: ' . $e->getMessage() . \PHP_EOL);
            $this->state->finishMigration(false, $e->getMessage());
            $io->error('Migration failed: ' . $e->getMessage());

            return Command::FAILURE;
        } finally {
            $this->lock->release();
        }
    }

    /**
     * Delegate to Doctrine's migrate command, teeing its output to the log file.
     */
    private function runDoctrineMigrate(OutputInterface $output): int
    {
        $application = $this->getApplication();
        if ($application === null) {
            throw new \RuntimeException('Console application is not available.');
        }

        $migrate = $application->find('doctrine:migrations:migrate');

        $tee = new TeeOutput($output, fn (string $chunk) => $this->state->appendLog($chunk));

        // all_or_nothing is enabled in doctrine_migrations.yaml, so it is NOT
        // passed here (passing it a value is deprecated upstream).
        $migrateInput = new ArrayInput([
            'command' => 'doctrine:migrations:migrate',
            'version' => 'latest',
            '--allow-no-migration' => true,
        ]);
        $migrateInput->setInteractive(false);

        return $migrate->run($migrateInput, $tee);
    }
}
