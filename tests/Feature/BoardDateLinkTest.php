<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Service\EventConfigStore;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Every link the board renders has to name the day the board is showing.
 *
 * The board's day is midnight in the *display* timezone, which is not the timezone PHP is
 * configured with. Formatting that instant anywhere that does not know the display timezone shifts
 * it across a date boundary, so a board opened on the 14th offers its own views on the 13th - and
 * the same offset again on each hop, so a viewer walks backwards through the event one click at a
 * time. The rendered day in the header stays correct throughout, which is what makes it hard to
 * spot.
 *
 * Europe/Berlin is used here because the bug is invisible under the UTC default the suite otherwise
 * runs with: it only appears when the two zones disagree.
 */
final class BoardDateLinkTest extends DatabaseWebTestCase
{
    private const DAY = '2026-07-14';

    private Department $department;

    private function setUpBoard(string $timezone): void
    {
        $store = static::getContainer()->get(EventConfigStore::class);
        $store->set(EventConfigStore::KEY_TIMEZONE, $timezone);
        $store->flush();

        $this->department = new Department('Logistics', 'logistics-'.bin2hex(random_bytes(3)));
        $this->em->persist($this->department);

        $suffix = bin2hex(random_bytes(4));
        $group = new Group('Board '.$suffix, 'board-'.$suffix, 'ROLE_STAFF');
        $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => 'board:view']) ?? new Privilege('board:view');
        $this->em->persist($privilege);
        $group->addPrivilege($privilege);
        $this->em->persist($group);

        $user = new User();
        $user->setName('board-'.$suffix)->setEmail('board-'.$suffix.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'secret123'));
        $user->completeOnboarding();
        $this->em->persist($user->assignGroup($group, $this->department));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    private function url(string $date): string
    {
        return '/board/'.$this->department->getUuid().'/'.$date.'?view=overview';
    }

    /** @return list<string> */
    private function hrefs(\Symfony\Component\DomCrawler\Crawler $crawler, string $selector): array
    {
        return $crawler->filter($selector)->each(static fn ($node): string => (string) $node->attr('href'));
    }

    public function testTheViewLinksNameTheDayBeingShown(): void
    {
        $this->setUpBoard('Europe/Berlin');

        $crawler = $this->client->request('GET', $this->url(self::DAY));
        self::assertResponseIsSuccessful();

        $links = $this->hrefs($crawler, '[data-board-nav] a');
        self::assertCount(3, $links, 'the rail offers Overview, Staff and Shifts');

        foreach ($links as $href) {
            self::assertStringContainsString('/'.self::DAY.'?', $href, 'a view link left the day being shown');
        }
    }

    public function testTheDayPagerStepsExactlyOneDay(): void
    {
        $this->setUpBoard('Europe/Berlin');

        $crawler = $this->client->request('GET', $this->url(self::DAY));

        $links = $this->hrefs($crawler, '[data-board-datepager] a');
        self::assertCount(2, $links);
        self::assertStringContainsString('/2026-07-13?', $links[0]);
        self::assertStringContainsString('/2026-07-15?', $links[1]);
    }

    /** The live region re-fetches this URL, so a shifted day would quietly swap the board's contents. */
    public function testTheLiveRegionRefetchesTheDayBeingShown(): void
    {
        $this->setUpBoard('Europe/Berlin');

        $crawler = $this->client->request('GET', $this->url(self::DAY));
        $url = (string) $crawler->filter('[data-board-view]')->attr('data-live-stream-url-value');

        self::assertStringContainsString('/'.self::DAY.'/view', $url);
    }

    /**
     * Walking the pager must not drift. The original report was a day out per hop, which only shows
     * up once you follow a link rather than reading the one that was rendered.
     */
    public function testFollowingThePagerLandsOnTheDayItNamed(): void
    {
        $this->setUpBoard('Europe/Berlin');

        $crawler = $this->client->request('GET', $this->url(self::DAY));
        $next = $this->hrefs($crawler, '[data-board-datepager] a')[1];

        $crawler = $this->client->request('GET', $next);
        self::assertResponseIsSuccessful();

        foreach ($this->hrefs($crawler, '[data-board-nav] a') as $href) {
            self::assertStringContainsString('/2026-07-15?', $href, 'the day drifted on the way to the next day');
        }
    }

    /** West of UTC the shift runs the other way, so the fix cannot be a fixed offset. */
    public function testTheSameHoldsWestOfUtc(): void
    {
        $this->setUpBoard('America/New_York');

        $crawler = $this->client->request('GET', $this->url(self::DAY));

        foreach ($this->hrefs($crawler, '[data-board-nav] a') as $href) {
            self::assertStringContainsString('/'.self::DAY.'?', $href);
        }
    }
}
