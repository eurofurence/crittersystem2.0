<?php

namespace App\Tests\Integration;

use App\Entity\Settings;
use App\Entity\User;
use App\Service\Shift\ShiftFilterMemory;
use App\Tests\DatabaseTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Remembering the filters somebody last chose on a shift screen.
 *
 * The hard part is not storing them, it is telling three arrivals apart. A URL carrying filters is
 * somebody's own choice or a link they were sent, and must win. A form submitted with everything
 * cleared has to clear the memory, even though an unticked checkbox submits nothing and therefore
 * looks identical to a plain visit. Anything else is a plain visit, and gets what they had before.
 */
final class ShiftFilterMemoryTest extends DatabaseTestCase
{
    private function memory(): ShiftFilterMemory
    {
        return static::getContainer()->get(ShiftFilterMemory::class);
    }

    private function user(): User
    {
        $user = new User();
        $user->setName('u'.bin2hex(random_bytes(3)))
            ->setEmail('u'.bin2hex(random_bytes(3)).'@e.com')
            ->setApiKey(bin2hex(random_bytes(16)))
            ->setPassword('x');
        $settings = new Settings($user);
        $user->setSettings($settings);
        $this->em->persist($settings);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testAPlainVisitGetsBackWhatWasChosenBefore(): void
    {
        $user = $this->user();
        $location = (string) Uuid::v4();

        $this->memory()->resolve($user, ShiftFilterMemory::SURFACE_BROWSE, ['location' => $location, 'available' => '1']);

        self::assertSame(
            ['location' => $location, 'available' => '1'],
            $this->memory()->resolve($user, ShiftFilterMemory::SURFACE_BROWSE, []),
        );
    }

    /** A link somebody was sent shows what the sender meant, not what the recipient last looked at. */
    public function testAUrlCarryingFiltersWinsOverWhatWasRemembered(): void
    {
        $user = $this->user();
        $remembered = (string) Uuid::v4();
        $linked = (string) Uuid::v4();

        $this->memory()->resolve($user, ShiftFilterMemory::SURFACE_BROWSE, ['location' => $remembered]);

        self::assertSame(
            ['location' => $linked],
            $this->memory()->resolve($user, ShiftFilterMemory::SURFACE_BROWSE, ['location' => $linked]),
        );
    }

    /**
     * The marker is the whole point: without it this request is indistinguishable from a plain
     * visit, and the filters the user just cleared would be handed straight back to them.
     */
    public function testClearingEveryFilterIsRememberedAsCleared(): void
    {
        $user = $this->user();
        $this->memory()->resolve($user, ShiftFilterMemory::SURFACE_BROWSE, ['location' => (string) Uuid::v4()]);

        self::assertSame([], $this->memory()->resolve($user, ShiftFilterMemory::SURFACE_BROWSE, [ShiftFilterMemory::MARKER => '1']));
        self::assertSame([], $this->memory()->resolve($user, ShiftFilterMemory::SURFACE_BROWSE, []));
    }

    /** The day expires, so it is never among what comes back. */
    public function testTheDayIsNeverRemembered(): void
    {
        $user = $this->user();

        $this->memory()->resolve($user, ShiftFilterMemory::SURFACE_BROWSE, [
            'location' => $location = (string) Uuid::v4(),
            'date' => '2026-08-01',
        ]);

        self::assertSame(['location' => $location], $this->memory()->resolve($user, ShiftFilterMemory::SURFACE_BROWSE, []));
    }

    /** Stored values are user input on a round trip, so they are checked again on the way out. */
    public function testARememberedValueThatIsNoLongerValidIsDropped(): void
    {
        $user = $this->user();
        $user->getSettings()->setShiftFilters([
            ShiftFilterMemory::SURFACE_BROWSE => ['location' => 'not-a-uuid', 'available' => '1'],
        ]);
        $this->em->flush();

        self::assertSame(['available' => '1'], $this->memory()->recall($user, ShiftFilterMemory::SURFACE_BROWSE));
    }

    public function testASurfaceDoesNotRememberAnotherSurfacesFilters(): void
    {
        $user = $this->user();

        $this->memory()->resolve($user, ShiftFilterMemory::SURFACE_BROWSE, ['location' => (string) Uuid::v4()]);

        self::assertSame([], $this->memory()->recall($user, ShiftFilterMemory::SURFACE_APPLY));
    }

    /**
     * The grid's checkbox submits an empty value when unticked, which it reads as "not my
     * departments only". Stored verbatim that would vanish and quietly retick the box.
     */
    public function testUntickingMyDepartmentsOnlySurvivesTheRoundTrip(): void
    {
        $user = $this->user();

        $this->memory()->resolve($user, ShiftFilterMemory::SURFACE_APPLY, [ShiftFilterMemory::MARKER => '1', 'scope' => '']);

        self::assertSame(['scope' => 'all'], $this->memory()->recall($user, ShiftFilterMemory::SURFACE_APPLY));
    }
}
