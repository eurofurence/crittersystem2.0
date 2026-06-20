<?php

namespace App\Tests\Integration;

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
