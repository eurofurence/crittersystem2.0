<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Entity\Worklog;
use App\Enum\DepartmentPosition;
use App\Service\DepartmentMemberService;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The type-ahead user pickers that replaced the eager user dropdowns. What matters here is the
 * search contract: username-only matching (an email substring must NOT enumerate addresses, the
 * property that tightened these endpoints away from the old permissive search), the public UUID as
 * the result identifier, and that each endpoint is gated by its screen's privilege.
 */
final class UserSearchPickerTest extends DatabaseWebTestCase
{
    /** @param string[] $privileges */
    private function makeUser(string $name, array $privileges = [], ?string $role = null): User
    {
        $group = new Group('G'.$name, 'g-'.$name, $role);
        foreach ($privileges as $p) {
            $priv = new Privilege($p);
            $this->em->persist($priv);
            $group->addPrivilege($priv);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** @return array<int, array{id: string, name: string, staff: bool, avatar: ?string}> */
    private function results(): array
    {
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true)['results'];
    }

    /**
     * The search matches usernames only. "@example.com" is a substring of every seeded address, so
     * a search that touched the email column would enumerate the whole user table from one term.
     */
    public function testWorklogSearchMatchesUsernameButNeverEmail(): void
    {
        $editor = $this->makeUser('editor', ['user:worklog:edit']);
        $this->makeUser('bumblebee');
        $this->client->loginUser($editor);

        $this->client->request('GET', '/manage/worklogs/user-search?q=bumble');
        $names = array_column($this->results(), 'name');
        self::assertContains('bumblebee', $names);

        $this->client->request('GET', '/manage/worklogs/user-search?q=example.com');
        self::assertSame([], $this->results(), 'email substrings must not enumerate users');
    }

    public function testWorklogSearchResultIdIsThePublicUuidNotThePrimaryKey(): void
    {
        $editor = $this->makeUser('editor', ['user:worklog:edit']);
        $target = $this->makeUser('quokka');
        $this->client->loginUser($editor);

        $this->client->request('GET', '/manage/worklogs/user-search?q=quokka');
        $results = $this->results();
        self::assertCount(1, $results);
        self::assertSame((string) $target->getUuid(), $results[0]['id']);
        self::assertNotSame((string) $target->getId(), $results[0]['id']);
    }

    public function testWorklogSearchRequiresThePrivilege(): void
    {
        $this->client->loginUser($this->makeUser('nobody', [], 'ROLE_STAFF'));
        $this->client->request('GET', '/manage/worklogs/user-search?q=x');
        self::assertResponseStatusCodeSame(403);
    }

    public function testManageWorklogFormBindsThePickedUserByUuid(): void
    {
        $editor = $this->makeUser('logger', ['user:worklog:edit']);
        $subject = $this->makeUser('subjecto');
        $this->client->loginUser($editor);

        $crawler = $this->client->request('GET', '/manage/worklogs/new');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="worklog[_token]"]')->attr('value');

        $this->client->request('POST', '/manage/worklogs/new', ['worklog' => [
            '_token' => $token,
            'user' => (string) $subject->getUuid(),
            'hours' => '2.5',
            'workedAt' => '2026-07-10T09:00',
            'comment' => '',
        ]]);
        self::assertResponseRedirects('/manage/worklogs');

        $logs = $this->em->getRepository(Worklog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame($subject->getId(), $logs[0]->getUser()->getId());
    }

    /** The submitted uuid belongs to nobody, and a worklog must not be persisted against it. */
    public function testManageWorklogFormRejectsAnUnknownUser(): void
    {
        $editor = $this->makeUser('logger', ['user:worklog:edit']);
        $this->client->loginUser($editor);

        $crawler = $this->client->request('GET', '/manage/worklogs/new');
        $token = $crawler->filter('input[name="worklog[_token]"]')->attr('value');

        $this->client->request('POST', '/manage/worklogs/new', ['worklog' => [
            '_token' => $token,
            'user' => 'de305d54-75b4-431b-adb2-eb6b9e546013',
            'hours' => '1',
            'workedAt' => '2026-07-10T09:00',
            'comment' => '',
        ]]);

        self::assertCount(0, $this->em->getRepository(Worklog::class)->findAll(), 'an unknown uuid must not persist a worklog');
    }

    public function testBadgeSearchDoesNotMatchEmail(): void
    {
        $assigner = $this->makeUser('assigner', ['badge:assign']);
        $this->makeUser('wombat');
        $this->client->loginUser($assigner);

        $this->client->request('GET', '/manage/badges/assign/search?q=wombat');
        self::assertContains('wombat', array_column($this->results(), 'name'));

        $this->client->request('GET', '/manage/badges/assign/search?q=example.com');
        self::assertSame([], $this->results(), 'badge search tightened to username-only');
    }

    public function testStaffStatsSearchIsAdminOnly(): void
    {
        $this->client->loginUser($this->makeUser('plainstaff', [], 'ROLE_STAFF'));
        $this->client->request('GET', '/staff/stats/user-search?q=x');
        self::assertResponseStatusCodeSame(403);
    }

    public function testStaffNotesPageRendersThePicker(): void
    {
        $this->client->loginUser($this->makeUser('noter', [], 'ROLE_STAFF'));
        $crawler = $this->client->request('GET', '/staff/notes');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('[data-controller="user-select"][data-user-select-multiple-value="false"]')->count());
    }

    /** ?user= pre-selects a target, which exercises the chip render path that carries no avatar. */
    public function testStaffStatsRendersThePreselectedTargetChip(): void
    {
        $admin = $this->makeUser('statsadmin', ['global:admin'], 'ROLE_STAFF');
        $other = $this->makeUser('othertarget');
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/staff/stats?user='.$other->getUuid());
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.user-select-chip[data-user-select-id="'.$other->getUuid().'"]')->count());
    }

    public function testBadgeAssignRendersTheMultiSelectWidgetOnceABadgeIsChosen(): void
    {
        $assigner = $this->makeUser('badger', ['badge:assign']);
        $badge = new \App\Entity\Badge('Security', 'security', \App\Entity\Badge::TYPE_STANDARD);
        $this->em->persist($badge);
        $this->em->flush();
        $this->client->loginUser($assigner);

        $crawler = $this->client->request('GET', '/manage/badges/assign?badge='.$badge->getUuid());
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('[data-controller="user-select"][data-user-select-multiple-value="true"]')->count());
    }

    public function testDepartmentAssignableSearchExcludesMembersAndSso(): void
    {
        $manager = $this->makeUser('deptmgr', ['department:view', 'department:member:manage'], 'ROLE_STAFF');

        $department = new Department('Art Show', 'art-show');
        $this->em->persist($department);
        foreach (DepartmentPosition::cases() as $position) {
            $this->em->persist(new Group($position->label(), $position->groupSlug(), 'ROLE_STAFF'));
        }
        $this->em->flush();

        $outsider = $this->makeUser('artoutsider');
        $existing = $this->makeUser('artmember');
        $sso = $this->makeUser('artsso');
        $sso->setAccountSource(User::SOURCE_SSO)->setSsoUserId('sub-artsso')->setSsoProvider('oidc');
        $this->em->flush();
        static::getContainer()->get(DepartmentMemberService::class)->setPosition($department, $existing, DepartmentPosition::STAFF);

        $this->client->loginUser($manager);
        $this->client->request('GET', '/departments/'.$department->getUuid().'/assignable-search?q=art');
        $names = array_column($this->results(), 'name');

        self::assertContains('artoutsider', $names);
        self::assertNotContains('artmember', $names, 'an existing member is not offered again');
        self::assertNotContains('artsso', $names, 'SSO-managed users are owned by the identity provider');
    }
}
