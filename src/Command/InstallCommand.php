<?php

namespace App\Command;

use App\Service\Install\Installer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Idempotent first-install seeder: creates the core RBAC groups and privileges
 * and, on a fresh database, a default admin user. Safe to re-run.
 *
 * The actual work lives in {@see Installer} so the web install wizard creates
 * accounts identically.
 */
#[AsCommand(
    name: 'app:install',
    description: 'Seed core groups, privileges and the default admin user (idempotent).',
)]
final class InstallCommand extends Command
{
    public function __construct(private readonly Installer $installer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('admin-username', null, InputOption::VALUE_REQUIRED, 'Default admin username', 'admin')
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'Default admin email', 'admin@localhost')
            ->addOption('admin-password', null, InputOption::VALUE_REQUIRED, 'Default admin password (random if omitted)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $created = $this->installer->installWithDefaultAdmin(
            (string) $input->getOption('admin-username'),
            (string) $input->getOption('admin-email'),
            $input->getOption('admin-password') !== null ? (string) $input->getOption('admin-password') : null,
        );

        $io->success('Core groups and privileges are installed.');

        if ($created !== null) {
            $io->writeln(\sprintf(
                'Created default admin "<info>%s</info>" with password: <comment>%s</comment>',
                $created['username'],
                $created['password'],
            ));
            $io->warning('Please change this password after the first login.');
        } else {
            $io->note('Users already exist; the default admin was not created.');
        }

        return Command::SUCCESS;
    }
}
