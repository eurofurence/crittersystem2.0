<?php

namespace App\Command;

use App\Entity\Contact;
use App\Entity\Group;
use App\Entity\PersonalData;
use App\Entity\Privilege;
use App\Entity\Settings;
use App\Entity\State;
use App\Entity\User;
use App\Repository\GroupRepository;
use App\Repository\PrivilegeRepository;
use App\Repository\UserRepository;
use App\Security\PrivilegeCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Idempotent first-install seeder: creates the core RBAC groups and privileges
 * and, on a fresh database, a default admin user. Safe to re-run.
 */
#[AsCommand(
    name: 'app:install',
    description: 'Seed core groups, privileges and the default admin user (idempotent).',
)]
final class InstallCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly GroupRepository $groups,
        private readonly PrivilegeRepository $privileges,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
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

        $this->seedPrivilegesAndGroups();

        $created = $this->createDefaultAdminIfNeeded(
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

    private function seedPrivilegesAndGroups(): void
    {
        /** @var array<string, Privilege> $privilegeByName */
        $privilegeByName = [];
        foreach (PrivilegeCatalog::PRIVILEGES as $name => $description) {
            $privilege = $this->privileges->findOneByName($name);
            if ($privilege === null) {
                $privilege = new Privilege($name, $description);
                $this->entityManager->persist($privilege);
            } else {
                $privilege->setDescription($description);
            }
            $privilegeByName[$name] = $privilege;
        }

        foreach (PrivilegeCatalog::GROUPS as $id => $definition) {
            $group = $this->groups->find($id);
            if ($group === null) {
                $group = new Group($id, $definition['name'], $definition['slug']);
                $this->entityManager->persist($group);
            } else {
                $group->setName($definition['name'])->setSlug($definition['slug']);
            }

            foreach ($definition['privileges'] as $privilegeName) {
                $group->addPrivilege($privilegeByName[$privilegeName]);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * @return array{username: string, password: string}|null
     */
    private function createDefaultAdminIfNeeded(string $username, string $email, ?string $password): ?array
    {
        if ($this->users->countAll() > 0) {
            return null;
        }

        $plainPassword = $password ?? bin2hex(random_bytes(8));

        $admin = new User();
        $admin->setName($username)
            ->setEmail($email)
            ->setApiKey(bin2hex(random_bytes(16)))
            ->setPassword($this->passwordHasher->hashPassword($admin, $plainPassword))
            ->setPersonalData(new PersonalData($admin))
            ->setContact(new Contact($admin))
            ->setSettings(new Settings($admin))
            ->setState(new State($admin));

        foreach ([90, 80] as $groupId) {
            $group = $this->groups->find($groupId);
            if ($group !== null) {
                $admin->addGroup($group);
            }
        }

        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        return ['username' => $username, 'password' => $plainPassword];
    }
}
