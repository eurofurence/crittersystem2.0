<?php

namespace App\Service\Install;

use App\Entity\ConsentText;
use App\Entity\Contact;
use App\Entity\Department;
use App\Entity\Group;
use App\Entity\PersonalData;
use App\Entity\PrivacyNotice;
use App\Entity\Privilege;
use App\Entity\Settings;
use App\Entity\ShiftTask;
use App\Entity\State;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Repository\ConsentTextRepository;
use App\Repository\GroupRepository;
use App\Repository\PrivacyNoticeRepository;
use App\Repository\PrivilegeRepository;
use App\Repository\ShiftTaskRepository;
use App\Repository\VolunteerTypeRepository;
use App\Service\PrivacyNoticeProvider;
use App\Audit\CertificateAuthority;
use App\Badge\BadgeCatalog;
use App\Entity\Badge;
use App\Repository\BadgeRepository;
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
    /** The first administrator is a global admin. */
    private const ADMIN_GROUP_SLUG = 'global-admin';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly GroupRepository $groups,
        private readonly PrivilegeRepository $privileges,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly CertificateAuthority $certificateAuthority,
        private readonly BadgeRepository $badges,
        private readonly ShiftTaskRepository $shiftTasks,
        private readonly VolunteerTypeRepository $volunteerTypes,
        private readonly PrivacyNoticeRepository $privacyNotices,
        private readonly PrivacyNoticeProvider $privacyProvider,
        private readonly ConsentTextRepository $consentTexts,
    ) {
    }

    /** Global Shift Tasks available to every department. */
    private const GLOBAL_SHIFT_TASKS = [
        'Setup', 'Tear down', 'Helper', 'Runner', 'Certification', 'Assistant', 'Staff Shift',
    ];

    /**
     * Volunteer Types seeded on first deployment. Flags obey the
     * flag interdependencies: name => [staffOnly, showOnDashboard, hideOnShiftView, selfSignup, sortOrder].
     * The two base types sort ahead of everything an admin adds later.
     */
    /** role => [name, staffOnly, showOnDashboard, hideOnShiftView, shiftSelfSignup, sortOrder, global] */
    private const SEED_VOLUNTEER_TYPES = [
        VolunteerType::ROLE_VOLUNTEER => ['Volunteer', false, true, false, true, 20, true],
        VolunteerType::ROLE_STAFF => ['Staff', true, false, true, true, 10, true],
    ];

    /**
     * Create or update every core privilege and group from the catalog. Safe to re-run; only the
     * diff is persisted, and each group's permissions converge on the catalog definition.
     *
     * The audit-signing certificate is created here because it must exist before any legal export.
     */
    public function seedPrivilegesAndGroups(): void
    {
        /** @var array<string, Privilege> $privilegeByName */
        $privilegeByName = [];
        foreach (PrivilegeCatalog::PERMISSIONS as $name => $meta) {
            $privilege = $this->privileges->findOneByName($name);
            if ($privilege === null) {
                $privilege = new Privilege($name, $meta[0]);
                $this->entityManager->persist($privilege);
            } else {
                $privilege->setDescription($meta[0]);
            }
            $privilegeByName[$name] = $privilege;
        }

        foreach (PrivilegeCatalog::GROUPS as $slug => $definition) {
            $group = $this->groups->findOneBySlug($slug);
            if ($group === null) {
                $group = new Group($definition['name'], $slug, $definition['role']);
                $this->entityManager->persist($group);
            } else {
                $group->setName($definition['name'])->setRole($definition['role']);
            }

            $desired = PrivilegeCatalog::expandPermissions($definition['permissions']);
            $desiredSet = array_fill_keys($desired, true);
            foreach ($group->getPrivileges()->toArray() as $existing) {
                if (!isset($desiredSet[$existing->getName()])) {
                    $group->removePrivilege($existing);
                }
            }
            foreach ($desired as $permissionName) {
                $group->addPrivilege($privilegeByName[$permissionName]);
            }
        }

        foreach (BadgeCatalog::BADGES as $slug => $definition) {
            $badge = $this->badges->findOneBySlug($slug);
            if ($badge === null) {
                $badge = new Badge($definition['name'], $slug, $definition['type']);
                $this->entityManager->persist($badge);
            } else {
                $badge->setName($definition['name'])->setType($definition['type']);
            }
            $badge->setPriority($definition['priority'])->setColor($definition['color']);
        }

        $this->entityManager->flush();

        $this->seedDomainDefaults();

        $this->certificateAuthority->ensureCertificate();
    }

    /**
     * Seed the global Shift Tasks and the base Volunteer Types every deployment needs. Idempotent:
     * only missing rows are created.
     *
     * Shift tasks are matched as GLOBAL specifically - names are unique per department, so a
     * department may already own one of the same name and that must not suppress the global one.
     *
     * Volunteer types are matched on the role, then on the shipped name for an installation seeded
     * before roles existed. Matching on the name alone would seed a second base type beside one an
     * event has renamed, leaving onboarding two to choose from.
     *
     * The General department is seeded as the home for shifts that have no other.
     */
    public function seedDomainDefaults(): void
    {
        $departments = $this->entityManager->getRepository(Department::class);
        if ($departments->findOneBy(['slug' => 'general']) === null) {
            $this->entityManager->persist(new Department('General', 'general'));
        }

        foreach (self::GLOBAL_SHIFT_TASKS as $name) {
            if ($this->shiftTasks->findOneBy(['name' => $name, 'department' => null]) === null) {
                $this->entityManager->persist(new ShiftTask($name));
            }
        }

        foreach (self::SEED_VOLUNTEER_TYPES as $role => [$name, $staffOnly, $showOnDashboard, $hideOnShiftView, $selfSignup, $sortOrder, $global]) {
            $type = $this->volunteerTypes->findOneByRole($role) ?? $this->volunteerTypes->findOneByName($name);
            if ($type === null) {
                $this->entityManager->persist(
                    (new VolunteerType($name))
                        ->setRole($role)
                        ->setStaffOnly($staffOnly)
                        ->setShowOnDashboard($showOnDashboard)
                        ->setHideOnShiftView($hideOnShiftView)
                        ->setShiftSelfSignup($selfSignup)
                        ->setSortOrder($sortOrder)
                        ->setGlobal($global)
                );
            } elseif ($type->getRole() === null) {
                $type->setRole($role);
            }
        }

        $this->seedConsentText();

        $this->entityManager->flush();
    }

    /**
     * Seed the default English consent disclaimer shown during onboarding, so a
     * fresh install has a working consent gate before any admin edits the text.
     * Only ever creates the en_US row when absent; an existing (possibly
     * admin-edited) row is left untouched. The %variables resolve at render time.
     */
    private function seedConsentText(): void
    {
        if ($this->consentTexts->findOneByLocale('en_US') !== null) {
            return;
        }

        $text = (new ConsentText('en_US'))
            ->setHeaderTitle('Data Protection & Privacy')
            ->setHeaderBody(
                'To take part as a volunteer at %event_name we need to process some personal data about '
                .'you - such as your name, contact details, availability and role preferences. We use it '
                .'only to organise volunteer shifts and to keep in touch with you about them.'
            )
            ->setCheckboxLabel(
                'I agree to share my personal data with the %event_name volunteer team so that I can use '
                .'this system. I understand that no tracking cookies are used, and that my data will be '
                .'permanently deleted within %deletion_days days after the event.'
            )
            ->setFooter(
                'You can withdraw your consent and request deletion of your data at any time. See the '
                .'full privacy notice above for details.'
            );

        $this->entityManager->persist($text);
    }

    /**
     * Store the essentials of the privacy notice captured during setup (event
     * name, data controller, contact email, retention period). A fresh notice
     * gets the shipped default body so the full text is present and editable at
     * Manage → Privacy notice; an existing notice keeps its body.
     */
    public function savePrivacyNotice(string $eventName, string $controllerOrg, string $contactEmail, int $deletionDays): void
    {
        $notice = $this->privacyNotices->current();
        if ($notice === null) {
            $notice = new PrivacyNotice();
            $this->privacyProvider->applyDefault($notice);
            $this->entityManager->persist($notice);
        }

        $notice->setEventName($eventName)
            ->setControllerOrg($controllerOrg)
            ->setContactEmail($contactEmail)
            ->setDeletionDays(max(1, $deletionDays));

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

        $group = $this->groups->findOneBySlug(self::ADMIN_GROUP_SLUG);
        if ($group !== null) {
            $admin->addGroup($group);
        }

        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        return $admin;
    }

    /**
     * Seed the catalog and, only on a brand-new database, create a default
     * admin with the given (or a generated) password. On a database that already has users the
     * groups and privileges are still brought current, but no existing user is touched.
     *
     * @return array{username: string, password: string}|null the created
     *   credentials, or null when users already existed
     */
    public function installWithDefaultAdmin(string $username, string $email, ?string $password): ?array
    {
        if ($this->userCount() > 0) {
            $this->seedPrivilegesAndGroups();

            return null;
        }

        $plainPassword = $password ?? bin2hex(random_bytes(8));
        $this->createAdmin($username, $email, $plainPassword); // also seeds the catalog

        return ['username' => $username, 'password' => $plainPassword];
    }
}
