<?php

namespace App\Tests\Browser;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Entity\UserConsent;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The department member tables, in a real browser.
 *
 * The regression this exists for: the member tables were wrapped in Turbo Frames for search and
 * paging, which silently captured every link inside them. Clicking a member's name asked Turbo to
 * find a `dept-members-staff` frame in the profile page, which has none, so it rendered "Content
 * missing" instead of navigating. The server returned 200 and the markup was correct - only a real
 * click shows it.
 */
final class DepartmentMembersBrowserTest extends BrowserTestCase
{
    private const PASSWORD = 'secret123';

    private function seed(): Department
    {
        $dept = new Department('Stage', 'stage');
        $this->em->persist($dept);

        $memberGroup = new Group('Member', 'member-grp', 'ROLE_STAFF');
        $this->em->persist($memberGroup);

        $group = new Group('Managers', 'mgr-'.bin2hex(random_bytes(2)), 'ROLE_STAFF');
        foreach (['department:manage', 'department:member:manage'] as $p) {
            $priv = new Privilege($p);
            $this->em->persist($priv);
            $group->addPrivilege($priv);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $manager = new User();
        $manager->setName('dept-mgr')->setEmail('dept-mgr@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $manager->setPassword($hasher->hashPassword($manager, self::PASSWORD));
        $manager->completeOnboarding();
        $this->em->persist($manager);
        $this->em->persist($manager->assignGroup($group, $dept));

        // Enough members to force a pager, so the frame is doing real work.
        for ($i = 0; $i < 30; ++$i) {
            $member = new User();
            $member->setName(sprintf('member%02d', $i))
                ->setEmail(sprintf('member%02d@example.com', $i))
                ->setApiKey(bin2hex(random_bytes(16)));
            $member->setPassword('x');
            $member->completeOnboarding();
            $member->setConsent((new UserConsent($member))->setEmailVisible(true));
            $this->em->persist($member);
            $this->em->persist($member->getConsent());
            $this->em->persist($member->assignGroup($memberGroup, $dept));
        }

        $this->em->flush();

        return $dept;
    }

    private function open(Department $dept): void
    {
        $manager = $this->em->getRepository(User::class)->findOneBy(['email' => 'dept-mgr@example.com']);
        $this->browse();
        $this->signIn($manager, self::PASSWORD);
        $this->client->request('GET', '/departments/'.$dept->getUuid());
        $this->client->waitFor('#dept-members-staff', 10);
    }

    /** The reported failure mode, reproduced as a click. */
    public function testClickingAMemberOpensTheirProfileInsteadOfBreakingTheFrame(): void
    {
        $dept = $this->seed();
        $this->open($dept);

        $link = $this->client->getCrawler()->filter('#dept-members-staff a[href*="/users/"]')->first();
        $href = $link->attr('href');
        $link->click();

        /*
         * The whole point: the browser must LEAVE this page. If the frame captured the click the URL
         * never changes and this wait times out - which is the failure the test is here to produce.
         */
        $this->client->wait(10)->until(
            fn (): bool => str_contains($this->client->getCurrentURL(), '/users/'),
            'the click stayed inside the member frame instead of navigating to the profile',
        );

        self::assertStringContainsString($href, $this->client->getCurrentURL(), 'the whole page navigates to the profile');
        self::assertStringNotContainsString('Content missing', $this->client->getCrawler()->filter('body')->text());
        $this->assertNoConsoleErrors('the profile page reached from a member table');
    }

    /** The pager must still swap only its own frame; the page must not navigate. */
    public function testPagingSwapsTheTableWithoutLeavingThePage(): void
    {
        $dept = $this->seed();
        $this->open($dept);
        $before = $this->client->getCurrentURL();

        $firstNames = $this->client->executeScript(
            'return Array.from(document.querySelectorAll("#dept-members-staff tbody tr td:first-child"))'
            .'.map((c) => c.textContent.trim());'
        );

        $this->client->getCrawler()->filter('#dept-members-staff .page-link')->last()->click();
        $this->client->wait(10)->until(
            fn (): bool => $this->client->executeScript(
                'const c = document.querySelector("#dept-members-staff tbody tr td:first-child");'
                .'return c ? c.textContent.trim() : "";'
            ) !== ($firstNames[0] ?? ''),
        );

        self::assertNotSame($firstNames[0] ?? null, $this->client->executeScript(
            'return document.querySelector("#dept-members-staff tbody tr td:first-child").textContent.trim();'
        ), 'the table shows a different page');
        self::assertStringNotContainsString('Content missing', $this->client->getCrawler()->filter('body')->text());
        $this->assertNoConsoleErrors('the department page after paging');
    }
}
