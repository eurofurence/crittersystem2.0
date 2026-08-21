<?php

namespace App\Tests\Browser;

use App\Entity\GoodieCategory;
use App\Entity\GoodieDistribution;
use App\Entity\GoodieItem;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The counter behaviour of the info-desk page, which only a real browser shows.
 *
 * Nothing here is visible to a test that reads markup: whether ticking a row actually reaches the
 * Stimulus controller, whether the submit button is released, whether the confirm modal appears
 * before a revoke, and whether the page comes back on the tab the operator was working in. The
 * markup is identical in every one of those cases.
 */
final class GoodieBulkHandoverBrowserTest extends BrowserTestCase
{
    private const PASSWORD = 'secret123';

    /** Signing in lands on /news, so the desk group carries news:view to keep that page clean. */
    private function seed(): User
    {
        $group = new Group('Desk', 'desk-'.bin2hex(random_bytes(2)), 'ROLE_STAFF');
        foreach (['user:locate', 'goodie:view', 'goodie:distribute', 'shift:view', 'news:view'] as $name) {
            $privilege = new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $desk = new User();
        $desk->setName('desk')->setEmail('desk@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $desk->setPassword($hasher->hashPassword($desk, self::PASSWORD));
        $desk->addGroup($group);
        $desk->completeOnboarding();
        $this->em->persist($desk);

        $volunteer = new User();
        $volunteer->setName('volunteer')->setEmail('volunteer@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $volunteer->setPassword('x');
        $volunteer->completeOnboarding();
        $this->em->persist($volunteer);

        $category = new GoodieCategory('Swag');
        $this->em->persist($category);
        $this->em->persist((new GoodieItem($category, 'Festival Cup'))->setRequiredHours(0.0));
        $this->em->persist((new GoodieItem($category, 'Crew Shirt'))->setRequiredHours(0.0));

        $this->em->flush();

        return $volunteer;
    }

    private function signInAsDesk(): void
    {
        $desk = $this->em->getRepository(User::class)->findOneBy(['email' => 'desk@example.com']);
        $this->browse();
        $this->signIn($desk, self::PASSWORD);
    }

    /** @return GoodieDistribution[] */
    private function distributions(): array
    {
        $this->em->clear();

        return $this->em->getRepository(GoodieDistribution::class)->findBy([]);
    }

    public function testSelectAllReleasesTheSubmitAndHandsBothItemsOver(): void
    {
        $volunteer = $this->seed();
        $this->signInAsDesk();

        $this->client->request('GET', '/backstage/distribute/'.$volunteer->getUuid());
        $this->client->waitForVisibility('#goodie-pane-open', 10);

        $submit = $this->client->getCrawler()->filter('#goodie-pane-open [data-bulk-select-target="submit"]');
        self::assertFalse($submit->isEnabled(), 'nothing is ticked, so there is nothing to hand over yet');

        $this->client->getCrawler()->filter('#goodie-pane-open [data-bulk-select-target="all"]')->click();
        $this->client->waitFor('#goodie-pane-open [data-bulk-select-target="submit"]:not([disabled])', 10);
        self::assertSame('2', $this->client->getCrawler()->filter('#goodie-pane-open [data-bulk-select-target="count"]')->text());

        $this->client->getCrawler()->filter('#goodie-pane-open [data-bulk-select-target="submit"]')->click();
        $this->client->waitForElementToContain('body', 'Handed 2', 10);

        self::assertCount(2, $this->distributions(), 'one submit hands over every ticked row');
        $this->assertNoConsoleErrors('the bulk hand-out');
    }

    /**
     * The revoke asks first, and the page has to come back on the History tab: dropping the operator
     * back on the open tab after every correction is the behaviour this feature exists to end.
     */
    public function testARevokeIsConfirmedAndLeavesTheOperatorOnTheHistoryTab(): void
    {
        $volunteer = $this->seed();
        $item = $this->em->getRepository(GoodieItem::class)->findOneBy(['name' => 'Festival Cup']);
        $this->em->persist(new GoodieDistribution($volunteer, $item, 1));
        $this->em->flush();

        $this->signInAsDesk();
        $this->client->request('GET', '/backstage/distribute/'.$volunteer->getUuid());
        $this->client->waitForVisibility('#goodie-pane-open', 10);

        $this->client->getCrawler()->filter('a[href="#goodie-pane-history"]')->click();
        $this->client->waitForVisibility('#goodie-pane-history', 10);

        $this->client->getCrawler()->filter('#goodie-pane-history button[name="distribution"]:not([form])')->click();
        $this->client->waitForVisibility('.modal.show', 10);
        $this->client->getCrawler()->filter('.modal.show .btn-danger')->click();

        $this->client->waitForElementToContain('body', 'Revoked 1', 10);
        $this->client->waitForVisibility('#goodie-pane-history', 10);

        self::assertFalse(
            $this->client->getCrawler()->filter('#goodie-pane-open')->isDisplayed(),
            'the page must reopen on the tab the revoke was made from',
        );
        self::assertTrue($this->distributions()[0]->isRevoked());
        $this->assertNoConsoleErrors('the goodie revoke');
    }
}
