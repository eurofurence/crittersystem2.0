<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\PersonalData;
use App\Entity\Privilege;
use App\Entity\User;
use App\Entity\UserConsent;
use App\Entity\UserGroupAssignment;
use App\Service\DepartmentService;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Pagination and search on the department dashboard's member tables.
 *
 * These protect the reason the feature exists: a large department must cost the same to render as
 * a small one. Before this, every member cost about ten queries whether or not they were on screen,
 * so a 200-member department issued over 2000 of them per page view.
 */
final class DepartmentMemberPaginationTest extends DatabaseWebTestCase
{
    private Department $department;
    private Group $memberGroup;

    private function seedDepartment(): void
    {
        $this->department = new Department('Stage', 'stage');
        $this->em->persist($this->department);

        $this->memberGroup = new Group('Member', 'member-grp', 'ROLE_STAFF');
        $this->em->persist($this->memberGroup);
    }

    /** @param string|null $realName set to also give the member consented personal data */
    private function member(string $name, ?string $realName = null): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword('x');
        $user->completeOnboarding();
        $user->setConsent((new UserConsent($user))->setEmailVisible(true)->setFullNameVisible(true));
        if ($realName !== null) {
            $pd = new PersonalData($user);
            $pd->setFirstName($realName);
            $user->setPersonalData($pd);
            $this->em->persist($pd);
        }
        $this->em->persist($user);
        $this->em->persist($user->getConsent());
        // assignGroup() rather than a bare `new UserGroupAssignment`: only the former puts the
        // assignment on the user's own collection, and without it isStaff() sees no groups at all
        // for the rest of this EntityManager's life, quietly filing every member under Non-staff.
        $this->em->persist($user->assignGroup($this->memberGroup, $this->department));

        return $user;
    }

    private function manager(): User
    {
        $group = new Group('Managers', 'mgr-grp', 'ROLE_STAFF');
        $priv = new Privilege('department:manage');
        $this->em->persist($priv);
        $group->addPrivilege($priv);
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $manager = new User();
        $manager->setName('zzz-manager')->setEmail('mgr@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $manager->setPassword($hasher->hashPassword($manager, 'secret123'));
        $manager->completeOnboarding();
        $this->em->persist($manager);
        $this->em->persist($manager->assignGroup($group, $this->department));

        return $manager;
    }

    private function url(array $query = []): string
    {
        return '/departments/'.$this->department->getUuid().($query ? '?'.http_build_query($query) : '');
    }

    public function testOnlyOnePageOfMembersIsRenderedAndTheRestArePaged(): void
    {
        $this->seedDepartment();
        for ($i = 0; $i < 60; ++$i) {
            $this->member(sprintf('member%02d', $i));
        }
        $manager = $this->manager();
        $this->em->flush();
        $this->client->loginUser($manager);

        $crawler = $this->client->request('GET', $this->url());
        self::assertResponseIsSuccessful();

        $frame = $crawler->filter('#dept-members-staff');
        self::assertCount(DepartmentService::MEMBERS_PER_PAGE, $frame->filter('tbody tr'));
        self::assertStringContainsString('member00', $frame->text());
        self::assertStringNotContainsString('member30', $frame->text(), 'a later page must not be rendered');

        // The header still reports the whole section (60 members plus the manager), not the page size.
        $header = $crawler->filterXPath(
            '//turbo-frame[@id="dept-members-staff"]/parent::div/div[contains(@class, "card-header")]'
        );
        self::assertStringContainsString('(61)', $header->text());

        $page2 = $this->client->request('GET', $this->url(['staff_page' => 2]));
        $frame2 = $page2->filter('#dept-members-staff');
        self::assertStringContainsString('member25', $frame2->text());
        self::assertStringNotContainsString('member00', $frame2->text());
    }

    /**
     * Paging is only correct over a totally ordered list: without an explicit sort the database may
     * return members in a different order per request, so a member could show up on two pages or on
     * none. Pages must therefore be disjoint and, together, cover everyone exactly once.
     */
    public function testPagesAreOrderedDisjointAndCoverEveryMember(): void
    {
        $this->seedDepartment();
        for ($i = 0; $i < 60; ++$i) {
            $this->member(sprintf('member%02d', $i));
        }
        $manager = $this->manager();
        $this->em->flush();
        $this->client->loginUser($manager);

        $seen = [];
        for ($page = 1; $page <= 3; ++$page) {
            $crawler = $this->client->request('GET', $this->url(['staff_page' => $page]));
            $names = $crawler->filter('#dept-members-staff tbody tr td:first-child')->each(
                static fn ($node): string => trim($node->text()),
            );
            $seen = array_merge($seen, $names);
        }

        self::assertCount(61, $seen, '60 members plus the manager, each rendered exactly once');
        self::assertSame($seen, array_values(array_unique($seen)), 'no member may appear on two pages');

        $sorted = $seen;
        sort($sorted, \SORT_STRING);
        self::assertSame($sorted, $seen, 'members are paged in a stable username order');
    }

    public function testSearchFindsAMemberFromABeyondTheCurrentPage(): void
    {
        $this->seedDepartment();
        for ($i = 0; $i < 60; ++$i) {
            $this->member(sprintf('member%02d', $i));
        }
        $manager = $this->manager();
        $this->em->flush();
        $this->client->loginUser($manager);

        // member55 sits on page 3; the search must reach it without paging there.
        $crawler = $this->client->request('GET', $this->url(['staff_q' => 'member55']));
        self::assertResponseIsSuccessful();

        $frame = $crawler->filter('#dept-members-staff');
        self::assertCount(1, $frame->filter('tbody tr'));
        self::assertStringContainsString('member55', $frame->text());
    }

    public function testSearchMatchesTheUsernameButNeverTheRealName(): void
    {
        $this->seedDepartment();
        $this->member('alpha', 'Bernadette');
        $this->member('bravo');
        $manager = $this->manager();
        $this->em->flush();
        $this->client->loginUser($manager);

        // The manager may see Bernadette's name (she consented), but searching by it must not be a
        // way to confirm a real name - only usernames are matched.
        $crawler = $this->client->request('GET', $this->url(['staff_q' => 'Bernadette']));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#dept-members-staff tbody tr'));

        $crawler = $this->client->request('GET', $this->url(['staff_q' => 'alpha']));
        self::assertCount(1, $crawler->filter('#dept-members-staff tbody tr'));
    }

    public function testAnOutOfRangePageClampsToTheLastPageInsteadOfRenderingNothing(): void
    {
        $this->seedDepartment();
        for ($i = 0; $i < 30; ++$i) {
            $this->member(sprintf('member%02d', $i));
        }
        $manager = $this->manager();
        $this->em->flush();
        $this->client->loginUser($manager);

        // 30 members plus the manager, so the second page holds the remaining 6.
        $crawler = $this->client->request('GET', $this->url(['staff_page' => 999]));
        self::assertResponseIsSuccessful();
        self::assertCount(6, $crawler->filter('#dept-members-staff tbody tr'));
        self::assertStringContainsString('member29', $crawler->filter('#dept-members-staff')->text());
    }

    public function testSearchingOneSectionLeavesTheOthersUntouched(): void
    {
        $this->seedDepartment();
        for ($i = 0; $i < 30; ++$i) {
            $this->member(sprintf('member%02d', $i));
        }
        $manager = $this->manager();
        $this->em->flush();
        $this->client->loginUser($manager);

        $crawler = $this->client->request('GET', $this->url(['managers_q' => 'nothing-matches-this']));
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('#dept-members-managers tbody tr'), 'the searched section is filtered');
        self::assertCount(
            DepartmentService::MEMBERS_PER_PAGE,
            $crawler->filter('#dept-members-staff tbody tr'),
            'a search in one section must not filter another',
        );

        // Paging links of the untouched section carry the other section's search through.
        self::assertStringContainsString('managers_q=nothing-matches-this', $crawler->filter('#dept-members-staff')->html());
    }

    /**
     * Rows link out of the page (profiles, messages) and post to it (position, removal). A link or
     * form inside a <turbo-frame> navigates that frame by default, and the profile page has no such
     * frame, so Turbo rendered "Content missing" instead of the profile. The frame is therefore
     * marked target="_top" and only the pager opts back into it.
     */
    public function testRowLinksNavigateTheWholePageWhileThePagerStaysInTheFrame(): void
    {
        $this->seedDepartment();
        for ($i = 0; $i < 30; ++$i) {
            $this->member(sprintf('member%02d', $i));
        }
        $manager = $this->manager();
        $this->em->flush();
        $this->client->loginUser($manager);

        $crawler = $this->client->request('GET', $this->url());
        self::assertResponseIsSuccessful();

        foreach (['managers', 'staff', 'nonstaff'] as $key) {
            $frame = $crawler->filterXPath(sprintf('//turbo-frame[@id="dept-members-%s"]', $key));
            self::assertSame('_top', $frame->attr('target'), sprintf('the %s frame must not capture its row links', $key));
        }

        $staff = $crawler->filterXPath('//turbo-frame[@id="dept-members-staff"]');

        // Profile links must not carry a frame target of their own; the frame's _top now covers them.
        $profileLinks = $staff->filterXPath('.//a[contains(@href, "/users/")]');
        self::assertGreaterThan(0, $profileLinks->count());
        foreach ($profileLinks as $node) {
            self::assertNotSame('dept-members-staff', $node->getAttribute('data-turbo-frame'));
        }

        // The pager is the exception: it must still swap only its own table.
        $pageLinks = $staff->filterXPath('.//a[contains(@class, "page-link")]');
        self::assertGreaterThan(0, $pageLinks->count());
        foreach ($pageLinks as $node) {
            self::assertSame('dept-members-staff', $node->getAttribute('data-turbo-frame'));
        }
    }

    /**
     * The regression guard for the whole feature: rendering must not get more expensive as the
     * department grows. Asserted as a ceiling rather than an exact number so unrelated work on the
     * page does not make this brittle.
     */
    public function testQueryCountDoesNotGrowWithDepartmentSize(): void
    {
        $this->seedDepartment();
        for ($i = 0; $i < 150; ++$i) {
            $this->member(sprintf('member%03d', $i));
        }
        $manager = $this->manager();
        $this->em->flush();
        $this->client->loginUser($manager);

        $url = $this->url();
        // Warm the hours cache first; the cold path is measured by its own bound below.
        $this->client->request('GET', $url);

        $this->client->enableProfiler();
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $queries = $this->client->getProfile()->getCollector('db')->getQueryCount();
        self::assertLessThan(
            60,
            $queries,
            sprintf('rendering 150 members took %d queries; it must stay flat in the department size', $queries),
        );
    }
}
