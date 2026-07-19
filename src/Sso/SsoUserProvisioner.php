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
        private readonly SsoDepartmentPositions $positions,
        private readonly SsoGlobalRoles $globalRoles,
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

        $departments = $this->applyMappings($user, $claims->groups);
        $this->positions->apply($user, $claims->groups, $departments);
        $this->globalRoles->apply($user, $claims->groups);
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

    /**
     * @param string[] $groupIds
     *
     * @return \App\Entity\Department[] the departments the mappings placed the user in, whose
     *                                  positions {@see SsoDepartmentPositions} then resolves
     */
    private function applyMappings(User $user, array $groupIds): array
    {
        $departments = [];

        foreach ($groupIds as $groupId) {
            $mapping = $this->mappings->findOneBySsoGroupId($groupId);
            if ($mapping === null) {
                continue;
            }

            $department = $mapping->getDepartment();
            if ($department !== null) {
                $departments[spl_object_id($department)] = $department;
            }

            foreach ($mapping->getPermissionGroups() as $group) {
                if ($department !== null) {
                    $this->ensureScopedAssignment($user, $group, $department);
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

        return array_values($departments);
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

    /**
     * The mapping is authoritative, so the membership is confirmed straight away
     * rather than queued for a supporter - no manual step is needed for anything
     * SSO already tells us. A membership the user requested themselves and that
     * is still pending is confirmed too, once a mapping grants the same type.
     */
    private function ensureVolunteerType(User $user, \App\Entity\VolunteerType $type): void
    {
        $membership = $this->em->getRepository(UserVolunteerType::class)->findOneBy(['user' => $user, 'volunteerType' => $type]);
        if ($membership === null) {
            $membership = new UserVolunteerType($user, $type);
            $this->em->persist($membership);
        }
        if (!$membership->isConfirmed()) {
            $membership->setConfirmedBy($user);
        }
    }
}
