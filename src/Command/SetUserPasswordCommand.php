<?php

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Dev/admin helper: set (or reset) a user's password by username or email.
 * Generates a random password when none is given. Useful for getting into the
 * app after app:install created an admin with a random password.
 */
#[AsCommand(
    name: 'app:user:password',
    description: 'Set or reset a user password (by username or email).',
)]
final class SetUserPasswordCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Username or email of the account')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'New password (random if omitted)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $identifier = (string) $input->getArgument('username');
        $user = $this->users->findOneByUsernameOrEmail($identifier);
        if ($user === null) {
            $io->error(\sprintf('No user found matching "%s".', $identifier));

            return Command::FAILURE;
        }

        $password = $input->getOption('password') !== null
            ? (string) $input->getOption('password')
            : bin2hex(random_bytes(8));

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $this->entityManager->flush();

        $io->success(\sprintf('Password updated for "%s".', $user->getName()));
        $io->writeln(\sprintf('Password: <comment>%s</comment>', $password));

        return Command::SUCCESS;
    }
}
