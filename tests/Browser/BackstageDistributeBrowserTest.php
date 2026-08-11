<?php

namespace App\Tests\Browser;

use App\Entity\Certification;
use App\Entity\GoodieCategory;
use App\Entity\GoodieItem;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The hand-out panes on the info-desk volunteer page, in a real browser.
 *
 * The panes are switched by Bootstrap's tab data-api rather than by any code of ours, which means
 * the rest of the suite cannot tell a working set of tabs from four panes that are simply all
 * present in the markup and never become visible. Only a click shows it.
 */
final class BackstageDistributeBrowserTest extends BrowserTestCase
{
    private const PASSWORD = 'secret123';

    private function seed(): User
    {
        $group = new Group('Desk', 'desk-'.bin2hex(random_bytes(2)), 'ROLE_STAFF');
        // news:view is part of it because signing in lands on /news, and a 403 there would be a
        // severe console error that assertNoConsoleErrors() below would report.
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
        $this->em->persist((new GoodieItem($category, 'Loop Scarf'))->setRequiredHours(30.0));

        $certification = (new Certification('First Aid'))->setIsActive(true);
        $this->em->persist($certification);
        $gated = (new GoodieItem($category, 'First Aid Pin'))->setRequiredHours(0.0);
        $gated->addCertification($certification);
        $this->em->persist($gated);

        $this->em->flush();

        return $volunteer;
    }

    public function testTheHandoverPanesSwitchAndTheLadderRenders(): void
    {
        $volunteer = $this->seed();
        $desk = $this->em->getRepository(User::class)->findOneBy(['email' => 'desk@example.com']);

        $this->browse();
        $this->signIn($desk, self::PASSWORD);
        $this->client->request('GET', '/backstage/distribute/'.$volunteer->getUuid());
        $this->client->waitForVisibility('#goodie-pane-open', 10);

        // The ladder is server-rendered, so a missing marker here means the projection, not the CSS.
        self::assertCount(3, $this->client->getCrawler()->filter('.reward-timeline > li'));
        self::assertGreaterThan(0, $this->client->getCrawler()->filter('.reward-timeline > li.is-blocked')->count());

        self::assertFalse($this->client->getCrawler()->filter('#goodie-pane-blocked')->isDisplayed());
        $this->client->getCrawler()->filter('a[href="#goodie-pane-blocked"]')->click();
        $this->client->waitForVisibility('#goodie-pane-blocked', 10);

        self::assertFalse($this->client->getCrawler()->filter('#goodie-pane-open')->isDisplayed(), 'switching panes must hide the one left behind');
        self::assertStringContainsString('First Aid Pin', $this->client->getCrawler()->filter('#goodie-pane-blocked')->text());

        $this->assertNoConsoleErrors('the info-desk volunteer page');
    }
}
