<?php

declare(strict_types=1);

namespace App\Sso;

use App\Entity\User;
use App\Repository\SsoGroupMappingRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bulk pre-seeds users from an identity-provider group dump (each entry is a legacy IDP group with
 * its member list). It replays the exact SSO login provisioning for every user: a user's union of
 * legacy group ids is fed through {@see SsoUserProvisioner::applyGroups()}, so department/group/
 * badge/volunteer-type grants come from the existing {@see \App\Entity\SsoGroupMapping} rows and the
 * department position / global roles come from the configured SSO role ids - with STAFF and
 * no-admin as the safe fallbacks. The dump carries no email; created users get an undeliverable
 * placeholder that the provider overwrites on first login. Matching is strictly by sub, so the
 * import is additive and idempotent and never overwrites an existing user's identity fields.
 */
final class UserPreSeedImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SsoUserProvisioner $provisioner,
        private readonly SsoGroupMappingRepository $mappings,
        private readonly SsoRoleSettings $roles,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * Analyses the dump without writing anything.
     *
     * @param mixed $rows the decoded JSON (expected: list of entries)
     *
     * @return array{
     *     entriesTotal:int, entriesRecognized:int, entriesSkipped:list<array{id:string,name:string,type:string,users:int}>,
     *     usersTotal:int, usersToCreate:int, usersToUpdate:int, usersSkipped:int,
     *     recognized:list<array{id:string,name:string,type:string,users:int,target:string}>, warnings:list<string>
     * }
     */
    public function preview(mixed $rows): array
    {
        $plan = $this->plan($rows);

        $eligibleSubs = [];
        foreach ($plan['users'] as $sub => $info) {
            if ($info['eligible']) {
                $eligibleSubs[] = $sub;
            }
        }

        $existing = [];
        if ($eligibleSubs !== []) {
            foreach ($this->users->findBy(['ssoUserId' => $eligibleSubs]) as $user) {
                $existing[(string) $user->getSsoUserId()] = true;
            }
        }

        $toCreate = 0;
        $toUpdate = 0;
        foreach ($eligibleSubs as $sub) {
            isset($existing[$sub]) ? $toUpdate++ : $toCreate++;
        }

        $recognized = [];
        $skipped = [];
        foreach ($plan['entries'] as $entry) {
            if ($entry['recognized']) {
                $recognized[] = ['id' => $entry['id'], 'name' => $entry['name'], 'type' => $entry['type'], 'users' => $entry['users'], 'target' => $entry['target']];
            } else {
                $skipped[] = ['id' => $entry['id'], 'name' => $entry['name'], 'type' => $entry['type'], 'users' => $entry['users']];
            }
        }

        return [
            'entriesTotal' => \count($plan['entries']),
            'entriesRecognized' => \count($recognized),
            'entriesSkipped' => $skipped,
            'usersTotal' => \count($plan['users']),
            'usersToCreate' => $toCreate,
            'usersToUpdate' => $toUpdate,
            'usersSkipped' => \count($plan['users']) - \count($eligibleSubs),
            'recognized' => $recognized,
            'warnings' => $plan['warnings'],
        ];
    }

    /**
     * Creates/updates the users and applies their memberships inside a single transaction.
     *
     * Each user is flushed on its own, so the next created shell's username uniqueness check sees
     * the one before it.
     *
     * @param mixed $rows the decoded JSON (expected: list of entries)
     *
     * @return array{created:int, updated:int, skippedUsers:int, skippedBanned:int, warnings:list<string>}
     */
    public function import(mixed $rows): array
    {
        $plan = $this->plan($rows);

        $created = 0;
        $updated = 0;
        $skippedUsers = 0;
        $skippedBanned = 0;
        $warnings = $plan['warnings'];

        $this->em->wrapInTransaction(function () use ($plan, &$created, &$updated, &$skippedUsers, &$skippedBanned): void {
            foreach ($plan['users'] as $sub => $info) {
                if (!$info['eligible']) {
                    ++$skippedUsers;

                    continue;
                }
                if ($this->provisioner->isBanned($sub)) {
                    ++$skippedBanned;

                    continue;
                }

                $user = $this->provisioner->findBySub($sub);
                if ($user === null) {
                    $user = $this->provisioner->createPreSeedShell($sub, $info['username']);
                    ++$created;
                } else {
                    ++$updated;
                }

                $this->provisioner->applyGroups($user, $info['groupIds']);
                $this->em->flush();
            }
        });

        return ['created' => $created, 'updated' => $updated, 'skippedUsers' => $skippedUsers, 'skippedBanned' => $skippedBanned, 'warnings' => $warnings];
    }

    /**
     * Validates the dump and builds the per-user membership plan without touching the database
     * (beyond reading the mapping/role catalogues).
     *
     * @return array{
     *     users: array<string, array{username:string, groupIds:list<string>, eligible:bool}>,
     *     entries: list<array{id:string, name:string, type:string, users:int, recognized:bool, target:string}>,
     *     warnings: list<string>
     * }
     */
    private function plan(mixed $rows): array
    {
        if (!\is_array($rows) || !array_is_list($rows)) {
            throw new \InvalidArgumentException('The pre-seed file must be a JSON array of group entries.');
        }

        $recognizedIds = $this->recognizedIds();

        /** @var array<string, array{username:string, groupIds:array<string,true>, eligible:bool}> $users */
        $users = [];
        $entries = [];
        $warnings = [];

        foreach ($rows as $index => $row) {
            if (!\is_array($row) || !isset($row['id']) || !\is_string($row['id']) || $row['id'] === '') {
                $warnings[] = sprintf('Entry #%d has no id and was ignored.', (int) $index + 1);

                continue;
            }

            $groupId = $row['id'];
            $recognized = isset($recognizedIds[$groupId]);
            $members = (\is_array($row['users'] ?? null)) ? $row['users'] : [];

            foreach ($members as $member) {
                if (!\is_array($member) || !isset($member['user_id']) || !\is_string($member['user_id']) || $member['user_id'] === '') {
                    continue;
                }
                $sub = $member['user_id'];
                $username = (isset($member['username']) && \is_string($member['username'])) ? $member['username'] : '';

                if (!isset($users[$sub])) {
                    $users[$sub] = ['username' => $username, 'groupIds' => [], 'eligible' => false];
                } elseif ($users[$sub]['username'] === '' && $username !== '') {
                    $users[$sub]['username'] = $username;
                }
                $users[$sub]['groupIds'][$groupId] = true;
                if ($recognized) {
                    $users[$sub]['eligible'] = true;
                }
            }

            $entries[] = [
                'id' => $groupId,
                'name' => (isset($row['name']) && \is_string($row['name'])) ? $row['name'] : $groupId,
                'type' => (isset($row['type']) && \is_string($row['type'])) ? $row['type'] : 'unknown',
                'users' => \count($members),
                'recognized' => $recognized,
                'target' => $recognized ? $this->targetLabel($groupId) : '',
            ];
        }

        $out = [];
        foreach ($users as $sub => $info) {
            $out[$sub] = ['username' => $info['username'], 'groupIds' => array_keys($info['groupIds']), 'eligible' => $info['eligible']];
        }

        return ['users' => $out, 'entries' => $entries, 'warnings' => $warnings];
    }

    /**
     * The legacy group ids the importer recognises: every configured SSO group mapping, plus the
     * four configured SSO role ids (department-manager / shift-manager / global-admin / sub-admin).
     *
     * @return array<string,true>
     */
    private function recognizedIds(): array
    {
        $ids = [];
        foreach ($this->mappings->findAllOrdered() as $mapping) {
            $id = $mapping->getSsoGroupId();
            if ($id !== null && $id !== '') {
                $ids[$id] = true;
            }
        }
        foreach ([$this->roles->departmentManagerRole(), $this->roles->shiftManagerRole(), $this->roles->globalAdminRole(), $this->roles->subAdminRole()] as $roleId) {
            if ($roleId !== null && $roleId !== '') {
                $ids[$roleId] = true;
            }
        }

        return $ids;
    }

    private function targetLabel(string $groupId): string
    {
        $mapping = $this->mappings->findOneBySsoGroupId($groupId);
        if ($mapping === null) {
            $roles = [];
            if ($groupId === $this->roles->departmentManagerRole()) {
                $roles[] = 'department manager';
            }
            if ($groupId === $this->roles->shiftManagerRole()) {
                $roles[] = 'shift manager';
            }
            if ($groupId === $this->roles->globalAdminRole()) {
                $roles[] = 'global admin';
            }
            if ($groupId === $this->roles->subAdminRole()) {
                $roles[] = 'sub admin';
            }

            return 'role: '.($roles === [] ? 'unknown' : implode(', ', $roles));
        }

        $parts = [];
        $department = $mapping->getDepartment();
        if ($department !== null) {
            $parts[] = $department->getName();
        }
        $groups = \count($mapping->getPermissionGroups());
        $badges = \count($mapping->getBadges());
        $types = \count($mapping->getVolunteerTypes());
        if ($groups > 0) {
            $parts[] = $groups.' group'.($groups === 1 ? '' : 's');
        }
        if ($types > 0) {
            $parts[] = $types.' volunteer type'.($types === 1 ? '' : 's');
        }
        if ($badges > 0) {
            $parts[] = $badges.' badge'.($badges === 1 ? '' : 's');
        }

        return $parts === [] ? '(mapping without grants)' : implode(', ', $parts);
    }
}
