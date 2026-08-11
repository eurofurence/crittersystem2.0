<?php

namespace App\Tests\Integration;

use App\Entity\Certification;
use App\Entity\GoodieCategory;
use App\Entity\GoodieDistribution;
use App\Entity\GoodieItem;
use App\Entity\User;
use App\Entity\Worklog;
use App\Service\GoodieEligibilityService;
use App\Tests\DatabaseTestCase;

final class GoodieEligibilityServiceTest extends DatabaseTestCase
{
    private function service(): GoodieEligibilityService
    {
        return static::getContainer()->get(GoodieEligibilityService::class);
    }

    private function userWithHours(string $name, float $hours): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($user);

        $worklog = new Worklog($user);
        $worklog->setHours($hours);
        $this->em->persist($worklog);

        return $user;
    }

    private function item(GoodieCategory $cat, string $name, float $required, ?int $max = null): GoodieItem
    {
        $item = new GoodieItem($cat, $name);
        $item->setRequiredHours($required)->setMaxPerPerson($max);
        $this->em->persist($item);

        return $item;
    }

    public function testTiersAndDistributionRules(): void
    {
        $cat = new GoodieCategory('Apparel');
        $this->em->persist($cat);

        $user = $this->userWithHours('jane', 10.0);
        $shirt = $this->item($cat, 'Shirt', 5.0, 1);   // eligible
        $jacket = $this->item($cat, 'Jacket', 20.0);   // pending (needs 10 more)
        $pin = $this->item($cat, 'Pin', 0.0, 1);       // will become "claimed" after one give
        $this->em->flush();

        // Pre-claim the pin to its max.
        $this->em->persist(new GoodieDistribution($user, $pin, 1));
        $this->em->flush();

        $result = $this->service()->evaluate($user);
        self::assertSame(10.0, $result['hours']);

        $tiers = [];
        foreach ($result['rows'] as $row) {
            $tiers[$row['item']->getName()] = $row['tier'];
        }
        self::assertSame('eligible', $tiers['Shirt']);
        self::assertSame('pending', $tiers['Jacket']);
        self::assertSame('claimed', $tiers['Pin']);

        self::assertNull($this->service()->distributionError($user, $shirt, 1));
        self::assertStringContainsString('more hours', (string) $this->service()->distributionError($user, $jacket, 1));
        self::assertStringContainsString('per-person limit', (string) $this->service()->distributionError($user, $pin, 1));
        self::assertStringContainsString('Exceeds', (string) $this->service()->distributionError($user, $shirt, 2));
    }

    /**
     * The item finder orders by category first, which is what the hand-out tables want. A ladder
     * ordered that way runs backwards at every category boundary, so the timeline re-sorts.
     */
    public function testTheTimelineClimbsByRequiredHoursAcrossCategories(): void
    {
        $expensive = (new GoodieCategory('Rewards'))->setDisplayOrder(1);
        $cheap = (new GoodieCategory('Swag'))->setDisplayOrder(2);
        $this->em->persist($expensive);
        $this->em->persist($cheap);

        $user = $this->userWithHours('lena', 0.0);
        $this->item($expensive, 'Loop Scarf', 30.0);
        $this->item($cheap, 'Ribbon', 1.0);
        $this->em->flush();

        $evaluation = $this->service()->evaluate($user);
        self::assertSame(['Loop Scarf', 'Ribbon'], array_map(
            static fn (array $row): string => $row['item']->getName(),
            $evaluation['rows'],
        ), 'guard: the evaluation is category-ordered, so the re-sort below is doing real work');

        $rows = $this->service()->timeline($user)['rows'];
        self::assertSame(['Ribbon', 'Loop Scarf'], array_map(
            static fn (array $row): string => $row['item']->getName(),
            $rows,
        ));
        self::assertSame(['next', 'locked'], array_column($rows, 'marker'), 'only the cheapest unreached item is the target');
    }

    /**
     * An item held back by a certification is not a target: no number of hours unlocks it, so the
     * "next up" marker has to skip past it to something the volunteer can actually work towards.
     */
    public function testACertificationBlockedItemNeverTakesTheNextMarker(): void
    {
        $cat = new GoodieCategory('Gear');
        $this->em->persist($cat);
        $certification = (new Certification('First Aid'))->setIsActive(true);
        $this->em->persist($certification);

        $user = $this->userWithHours('milo', 0.0);
        $this->item($cat, 'First Aid Pin', 0.0)->addCertification($certification);
        $this->item($cat, 'Festival Cup', 5.0);
        $this->em->flush();

        $rows = $this->service()->timeline($user)['rows'];

        self::assertSame(['First Aid Pin', 'Festival Cup'], array_map(
            static fn (array $row): string => $row['item']->getName(),
            $rows,
        ));
        self::assertSame(['blocked', 'next'], array_column($rows, 'marker'));
    }

    public function testTheTimelineHasNoTargetWhenNothingIsLeftToEarn(): void
    {
        $cat = new GoodieCategory('Swag');
        $this->em->persist($cat);
        $user = $this->userWithHours('nora', 40.0);
        $this->item($cat, 'Ribbon', 1.0);
        $this->item($cat, 'Sticker', 2.0);
        $this->em->flush();

        $markers = array_column($this->service()->timeline($user)['rows'], 'marker');

        self::assertSame(['available', 'available'], $markers);
    }

    public function testInactiveItemCannotBeDistributed(): void
    {
        $cat = new GoodieCategory('Misc');
        $this->em->persist($cat);
        $user = $this->userWithHours('kyle', 50.0);
        $item = $this->item($cat, 'Retired', 0.0);
        $item->setIsActive(false);
        $this->em->flush();

        self::assertStringContainsString('not currently available', (string) $this->service()->distributionError($user, $item, 1));
    }
}
