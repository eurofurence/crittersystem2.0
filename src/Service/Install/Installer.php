<?php

namespace App\Service\Install;

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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Idempotent post-migration setup: seeds the core RBAC groups/privileges and
 * creates the first administrator. Shared by the `app:install` console command
 * (production / scripted installs) and the web install wizard, so both paths
 * build an admin account exactly the same way.
 */
final class Installer
{
    /** Default groups for the first administrator: Developer (90) + Bureaucrat (80). */
    private const ADMIN_GROUP_IDS = [90, 80];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly GroupRepository $groups,
        private readonly PrivilegeRepository $privileges,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * Create or update every core privilege and group from the catalog. Safe to
     * re-run; only the diff is persisted.
     */
    public function seedPrivilegesAndGroups(): void
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

    public function userCount(): int
    {
        return $this->users->countAll();
    }

    /**
     * Create an administrator account. Seeds groups/privileges first so the new
     * account is fully wired. Throws if the username/email is already taken.
     */
    public function createAdmin(string $username, string $email, string $plainPassword): User
    {
        $this->seedPrivilegesAndGroups();

        $admin = new User();
        $admin->setName($username)
            ->setEmail($email)
            ->setApiKey(bin2hex(random_bytes(16)))
            ->setPassword($this->passwordHasher->hashPassword($admin, $plainPassword))
            ->setPersonalData(new PersonalData($admin))
            ->setContact(new Contact($admin))
            ->setSettings(new Settings($admin))
            ->setState(new State($admin));

        foreach (self::ADMIN_GROUP_IDS as $groupId) {
            $group = $this->groups->find($groupId);
            if ($group !== null) {
                $admin->addGroup($group);
            }
        }

        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        return $admin;
    }

    /**
     * Seed the catalog and, only on a brand-new database, create a default
     * admin with the given (or a generated) password.
     *
     * @return array{username: string, password: string}|null the created
     *   credentials, or null when users already existed
     */
    public function installWithDefaultAdmin(string $username, string $email, ?string $password): ?array
    {
        if ($this->userCount() > 0) {
            // Keep groups/privileges current, but never touch existing users.
            $this->seedPrivilegesAndGroups();

            return null;
        }

        $plainPassword = $password ?? bin2hex(random_bytes(8));
        $this->createAdmin($username, $email, $plainPassword); // also seeds the catalog

        return ['username' => $username, 'password' => $plainPassword];
    }
}
