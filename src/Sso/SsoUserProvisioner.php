<?php

declare(strict_types=1);

namespace App\Sso;

use App\Entity\Contact;
use App\Entity\PersonalData;
use App\Entity\Settings;
use App\Entity\State;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Gdpr\BanChecker;
use App\Repository\SsoGroupMappingRepository;
use App\Repository\UserRepository;
use App\Service\UsernameGenerator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates or updates a local user from SSO claims and applies the group
 * mappings. SSO owns the username/full name/email (the user cannot change them);
 * the username is collision-suffixed on first creation and then kept stable.
 * Banned identities are refused.
 */
final class SsoUserProvisioner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly SsoGroupMappingRepository $mappings,
        private readonly UsernameGenerator $usernames,
        private readonly BanChecker $bans,
        private readonly string $providerLabel = 'oidc',
    ) {
    }

    public function provision(SsoClaims $claims): User
    {
        if ($this->bans->isBanned($claims->email, $claims->sub)) {
            throw new BannedIdentityException('This identity is banned.');
        }

        $user = $this->users->findOneBy(['ssoUserId' => $claims->sub]);
        if ($user === null) {
            $user = $this->create($claims);
        } else {
            // The provider is authoritative for these fields.
            $user->setEmail($claims->email);
            $this->applyName($user, $claims->name);
        }

        $this->applyMappings($user, $claims->groups);
        $this->em->flush();

        return $user;
    }

    private function create(SsoClaims $claims): User
    {
        $user = new User();
        $user->setAccountSource(User::SOURCE_SSO)
            ->setSsoUserId($claims->sub)
            ->setSsoProvider($this->providerLabel)
            ->setName($this->usernames->unique($claims->preferredUsername ?: $claims->email))
            ->setEmail($claims->email)
            ->setApiKey(bin2hex(random_bytes(16)))
            ->setPassword(bin2hex(random_bytes(16)))
            ->setPersonalData(new PersonalData($user))
            ->setContact(new Contact($user))
            ->setSettings(new Settings($user))
            ->setState(new State($user));

        $this->applyName($user, $claims->name);
        $this->em->persist($user);

        return $user;
    }

    private function applyName(User $user, ?string $name): void
    {
        if ($name === null || $name === '') {
            return;
        }
        $personal = $user->getPersonalData() ?? new PersonalData($user);
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];
        $personal->setFirstName($parts[0] ?? null)->setLastName($parts[1] ?? null);
        $user->setPersonalData($personal);
    }

    /** @param string[] $groupIds */
    private function applyMappings(User $user, array $groupIds): void
    {
        foreach ($groupIds as $groupId) {
            $mapping = $this->mappings->findOneBySsoGroupId($groupId);
            if ($mapping === null) {
                continue;
            }

            foreach ($mapping->getPermissionGroups() as $group) {
                if ($mapping->getDepartment() !== null) {
                    $this->ensureScopedAssignment($user, $group, $mapping->getDepartment());
                } else {
                    $user->addGroup($group);
                }
            }
            foreach ($mapping->getVolunteerTypes() as $type) {
                $this->ensureVolunteerType($user, $type);
            }
            foreach ($mapping->getBadges() as $badge) {
                $user->addBadge($badge);
            }
        }
    }

    private function ensureScopedAssignment(User $user, \App\Entity\Group $group, \App\Entity\Department $department): void
    {
        foreach ($user->getGroupAssignments() as $assignment) {
            if ($assignment->getGroup() === $group && $assignment->getDepartment() === $department) {
                return;
            }
        }
        $user->assignGroup($group, $department);
    }

    private function ensureVolunteerType(User $user, \App\Entity\VolunteerType $type): void
    {
        $existing = $this->em->getRepository(UserVolunteerType::class)->findOneBy(['user' => $user, 'volunteerType' => $type]);
        if ($existing === null) {
            $this->em->persist(new UserVolunteerType($user, $type));
        }
    }
}
