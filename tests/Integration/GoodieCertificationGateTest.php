<?php

namespace App\Tests\Integration;

use App\Entity\Certification;
use App\Entity\GoodieCategory;
use App\Entity\GoodieItem;
use App\Entity\User;
use App\Entity\UserCertification;
use App\Service\GoodieEligibilityService;
use App\Tests\DatabaseTestCase;

/**
 * A goodie that requires a certification is not handed to somebody who has not earned it.
 *
 * Held is the same predicate shift sign-up uses - approved or self-confirmed and not expired - so a
 * volunteer cannot be qualified to work a shift and unqualified for the goodie behind it.
 */
final class GoodieCertificationGateTest extends DatabaseTestCase
{
    private function service(): GoodieEligibilityService
    {
        return static::getContainer()->get(GoodieEligibilityService::class);
    }

    private function user(): User
    {
        $suffix = bin2hex(random_bytes(4));
        $user = new User();
        $user->setName('vol-'.$suffix)->setEmail('vol-'.$suffix.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($user);

        return $user;
    }

    private function certification(string $title, bool $active = true): Certification
    {
        $certification = (new Certification($title))->setIsActive($active);
        $this->em->persist($certification);

        return $certification;
    }

    private function item(string $name, Certification ...$required): GoodieItem
    {
        $category = new GoodieCategory('Swag '.bin2hex(random_bytes(3)));
        $this->em->persist($category);

        $item = (new GoodieItem($category, $name))->setRequiredHours(0.0);
        foreach ($required as $certification) {
            $item->addCertification($certification);
        }
        $this->em->persist($item);

        return $item;
    }

    private function hold(User $user, Certification $certification, string $status, ?\DateTimeImmutable $expires = null): void
    {
        $record = new UserCertification($user, $certification);
        $record->setStatus($status);
        if ($expires !== null) {
            $record->setDateExpires($expires);
        }
        $this->em->persist($record);
    }

    public function testAnItemWithoutCertificationsIsUnaffected(): void
    {
        $user = $this->user();
        $item = $this->item('Sticker');
        $this->em->flush();

        self::assertSame([], $this->service()->missingCertifications($user, $item));
        self::assertNull($this->service()->distributionError($user, $item, 1));
    }

    public function testEveryRequiredCertificationMustBeHeld(): void
    {
        $user = $this->user();
        $first = $this->certification('First Aid');
        $second = $this->certification('Fire Safety');
        $item = $this->item('Safety Pin', $first, $second);
        $this->hold($user, $first, UserCertification::STATUS_APPROVED);
        $this->em->flush();

        $missing = $this->service()->missingCertifications($user, $item);

        self::assertSame(['Fire Safety'], array_map(static fn (Certification $c): string => $c->getTitle(), $missing));
        self::assertStringContainsString('Fire Safety', (string) $this->service()->distributionError($user, $item, 1));
    }

    public function testHoldingAllOfThemAllowsTheHandover(): void
    {
        $user = $this->user();
        $first = $this->certification('First Aid');
        $second = $this->certification('Fire Safety');
        $item = $this->item('Safety Pin', $first, $second);
        $this->hold($user, $first, UserCertification::STATUS_APPROVED);
        $this->hold($user, $second, UserCertification::STATUS_SELF_CONFIRMED);
        $this->em->flush();

        self::assertSame([], $this->service()->missingCertifications($user, $item));
        self::assertNull($this->service()->distributionError($user, $item, 1));
    }

    public function testAnExpiredCertificationDoesNotCount(): void
    {
        $user = $this->user();
        $certification = $this->certification('First Aid');
        $item = $this->item('First Aid Pin', $certification);
        $this->hold($user, $certification, UserCertification::STATUS_APPROVED, new \DateTimeImmutable('-1 day'));
        $this->em->flush();

        self::assertCount(1, $this->service()->missingCertifications($user, $item), 'an expired certificate does not qualify anybody today');
    }

    public function testAPendingApplicationDoesNotCount(): void
    {
        $user = $this->user();
        $certification = $this->certification('First Aid');
        $item = $this->item('First Aid Pin', $certification);
        $this->hold($user, $certification, UserCertification::STATUS_PENDING);
        $this->em->flush();

        self::assertCount(1, $this->service()->missingCertifications($user, $item), 'applying for a certification is not holding it');
    }

    public function testARevokedCertificationDoesNotCount(): void
    {
        $user = $this->user();
        $certification = $this->certification('First Aid');
        $item = $this->item('First Aid Pin', $certification);
        $this->hold($user, $certification, UserCertification::STATUS_REVOKED);
        $this->em->flush();

        self::assertCount(1, $this->service()->missingCertifications($user, $item));
    }

    /** Deactivating a certification is how a requirement is retired. */
    public function testADeactivatedCertificationDoesNotGate(): void
    {
        $user = $this->user();
        $certification = $this->certification('Retired Training', active: false);
        $item = $this->item('Old Pin', $certification);
        $this->em->flush();

        self::assertSame([], $this->service()->missingCertifications($user, $item));
        self::assertNull($this->service()->distributionError($user, $item, 1));
    }

    public function testABlockedItemIsTieredAsBlockedNotEligible(): void
    {
        $user = $this->user();
        $certification = $this->certification('First Aid');
        $item = $this->item('First Aid Pin', $certification);
        $this->em->flush();

        $rows = $this->service()->evaluate($user)['rows'];
        $row = null;
        foreach ($rows as $candidate) {
            if ($candidate['item']->getId() === $item->getId()) {
                $row = $candidate;
            }
        }

        self::assertNotNull($row);
        self::assertSame('blocked', $row['tier'], 'an item the volunteer cannot claim must not read as eligible');
        self::assertCount(1, $row['missingCertifications']);
    }

    /**
     * Blocked outranks the hours gap: telling somebody they are three hours away from an item they
     * are barred from would be a lie.
     */
    public function testBlockedOutranksPending(): void
    {
        $user = $this->user();
        $certification = $this->certification('First Aid');
        $item = $this->item('First Aid Pin', $certification)->setRequiredHours(100.0);
        $this->em->flush();

        $rows = $this->service()->evaluate($user)['rows'];
        foreach ($rows as $row) {
            if ($row['item']->getId() === $item->getId()) {
                self::assertSame('blocked', $row['tier']);
            }
        }
    }

    /** The override answers for missing training, never for hours that were not worked. */
    public function testTheOverridePathStillEnforcesHours(): void
    {
        $user = $this->user();
        $certification = $this->certification('First Aid');
        $item = $this->item('First Aid Pin', $certification)->setRequiredHours(10.0);
        $this->em->flush();

        $error = $this->service()->distributionErrorIgnoringCertifications($user, $item, 1);

        self::assertNotNull($error);
        self::assertStringContainsString('hours', $error);
    }

    public function testTheOverridePathAllowsTheHandoverWhenOnlyCertificationsAreMissing(): void
    {
        $user = $this->user();
        $certification = $this->certification('First Aid');
        $item = $this->item('First Aid Pin', $certification);
        $this->em->flush();

        self::assertNotNull($this->service()->distributionError($user, $item, 1));
        self::assertNull($this->service()->distributionErrorIgnoringCertifications($user, $item, 1));
    }
}
