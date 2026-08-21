<?php

namespace App\Tests\Unit;

use App\Service\Statistics\EventStatistics;
use App\Service\Statistics\EventWindow;
use App\Service\Statistics\FunFact;
use App\Service\Statistics\FunFactBuilder;
use App\Service\Statistics\HoursTotals;
use App\Service\Statistics\Tallies;
use PHPUnit\Framework\TestCase;

/**
 * The fun comparisons.
 *
 * Two things matter here and neither is visible in a template: the distance comparison must pick a
 * reference the event actually exceeded, so nobody is told they went "0.002 times to the Moon"; and
 * the money comparison must stay hidden until somebody deliberately sets a rate, because it is a
 * loaded figure to put on a slide at a volunteer event.
 */
final class FunFactBuilderTest extends TestCase
{
    private function stats(float $workedHours, int $usersActive = 10): EventStatistics
    {
        return new EventStatistics(
            window: EventWindow::unbounded(),
            generatedAt: new \DateTimeImmutable('2026-06-10 12:00'),
            shiftsPublished: 100,
            shiftsDraft: 0,
            shiftsByAudience: [],
            shiftHoursByAudience: [],
            shiftsVolunteerAudience: 80,
            shiftsStaffAudience: 20,
            shiftHoursScheduled: 600.0,
            slotsNeeded: 200,
            slotsFilled: 180,
            usersRegistered: 500,
            usersStaff: 40,
            usersActive: $usersActive,
            usersActiveStaff: 3,
            usersActiveVolunteer: $usersActive - 3,
            planned: new HoursTotals($workedHours, $workedHours),
            worked: new HoursTotals($workedHours, $workedHours),
            workedStaff: new HoursTotals(0.0, 0.0),
            workedVolunteer: new HoursTotals($workedHours, $workedHours),
            dutyHours: 0.0,
            worklogHours: 0.0,
            entriesTotal: 180,
            entriesAssignment: 180,
            entriesApplication: 0,
            noshows: 2,
            departmentsWithShifts: 5,
            locationsUsed: 4,
            longestSingleShiftHours: 8.0,
            busiestDepartment: 'Ops',
        );
    }

    private function builder(): FunFactBuilder
    {
        return new FunFactBuilder();
    }

    /** @param list<FunFact> $facts */
    private function captions(array $facts): array
    {
        return array_map(static fn (FunFact $f): ?string => $f->captionKey, $facts);
    }

    public function testNoHoursProducesNoComparisons(): void
    {
        self::assertSame([], $this->builder()->derived($this->stats(0.0)));
    }

    /** 100 hours is 160 km: past a marathon, nowhere near the length of Germany. */
    public function testSmallEventIsMeasuredInMarathons(): void
    {
        $facts = $this->builder()->derived($this->stats(100.0));

        self::assertContains('manage.statistics.fun.scale.marathon.caption', $this->captions($facts));
    }

    /** 30,000 hours is 48,000 km, so the equator is the largest reference actually exceeded. */
    public function testLargeEventIsMeasuredInLapsOfTheEquator(): void
    {
        $facts = $this->builder()->derived($this->stats(30000.0));
        $captions = $this->captions($facts);

        self::assertContains('manage.statistics.fun.scale.earth.caption', $captions);
        self::assertNotContains('manage.statistics.fun.scale.moon.caption', $captions);
    }

    public function testDistanceUsesTheStatedPace(): void
    {
        $facts = $this->builder()->derived($this->stats(1000.0));
        $distance = $facts[0];

        self::assertSame('manage.statistics.fun.distance.caption', $distance->captionKey);
        self::assertEqualsWithDelta(1000.0 * FunFactBuilder::WALKING_PACE_KMH, $distance->value, 0.001);
        self::assertNotNull($distance->basisKey);
    }

    /** Every derived comparison states the assumption it rests on. */
    public function testEveryDerivedFactCarriesItsBasis(): void
    {
        foreach ($this->builder()->derived($this->stats(500.0)) as $fact) {
            self::assertNotNull($fact->basisKey, 'missing basis on '.($fact->captionKey ?? '?'));
        }
    }

    public function testMoneyComparisonIsHiddenWithoutARate(): void
    {
        $facts = $this->builder()->tallies(new Tallies(), $this->stats(500.0));

        self::assertNotContains('manage.statistics.fun.value.caption', $this->captions($facts));
    }

    public function testMoneyComparisonAppearsOnceARateIsSet(): void
    {
        $facts = $this->builder()->tallies(new Tallies(hourlyRate: 10.0), $this->stats(500.0));
        $value = array_values(array_filter($facts, static fn (FunFact $f): bool => $f->captionKey === 'manage.statistics.fun.value.caption'));

        self::assertCount(1, $value);
        self::assertEqualsWithDelta(5000.0, $value[0]->value, 0.001);
    }

    /** A coffee count unlocks comparisons a plain tally cannot make on its own. */
    public function testCoffeeTallyAddsDerivedComparisons(): void
    {
        $facts = $this->builder()->tallies(new Tallies(known: ['coffee' => 1500.0]), $this->stats(500.0));
        $captions = $this->captions($facts);

        self::assertContains('manage.statistics.tally.coffee.caption', $captions);
        self::assertContains('manage.statistics.fun.coffee_bathtubs.caption', $captions);
        self::assertContains('manage.statistics.fun.caffeine.caption', $captions);
    }

    /** A free-form row keeps the admin's own wording rather than a translation key. */
    public function testCustomTalliesUseTheirLiteralLabel(): void
    {
        $facts = $this->builder()->tallies(
            new Tallies(custom: [['label' => 'Wristbands issued', 'amount' => 1200.0]]),
            $this->stats(500.0),
        );

        $custom = array_values(array_filter($facts, static fn (FunFact $f): bool => $f->literalCaption !== null));

        self::assertCount(1, $custom);
        self::assertSame('Wristbands issued', $custom[0]->literalCaption);
        self::assertNull($custom[0]->captionKey);
    }
}
