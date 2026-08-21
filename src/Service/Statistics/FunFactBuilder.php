<?php

namespace App\Service\Statistics;

/**
 * Turns the event totals into the comparisons shown in the fun section.
 *
 * Every constant below is an assumption, and each one is surfaced next to the figure it produces so
 * nobody presents an estimate as a measurement. They are deliberately conservative: a headline that
 * overstates what volunteers did is worse than a modest one.
 *
 * Nothing here is a base number. The dashboard keeps this section visually separate for that
 * reason, and the two must not be mixed: a derived figure carries an error bar the totals do not.
 */
final class FunFactBuilder
{
    /**
     * Average walking pace assumed for somebody on shift, in km/h. Well below strolling pace,
     * because most of a shift is spent standing still.
     */
    public const WALKING_PACE_KMH = 1.6;

    /** Energy burned per hour of light standing work, in kcal. */
    public const KCAL_PER_HOUR = 150.0;

    private const EARTH_CIRCUMFERENCE_KM = 40075.0;
    private const MOON_DISTANCE_KM = 384400.0;
    private const MARATHON_KM = 42.195;
    private const GERMANY_LENGTH_KM = 876.0;

    private const BANANA_KCAL = 105.0;
    private const CAREER_HOURS = 68000.0;
    private const FEATURE_FILM_HOURS = 2.0;
    private const FULL_WORKING_DAY_HOURS = 8.0;
    private const HOURS_PER_YEAR = 8766.0;
    private const COACH_SEATS = 50.0;

    private const COFFEE_CUP_LITRES = 0.2;
    private const COFFEE_CAFFEINE_MG = 95.0;
    private const BATHTUB_LITRES = 150.0;

    /**
     * Distance references, largest last. The comparison shown is the largest one the event actually
     * exceeded, so a small event is measured in marathons and a large one in laps of the planet,
     * and neither is told it went "0.002 times to the Moon".
     */
    private const DISTANCE_SCALE = [
        'marathon' => self::MARATHON_KM,
        'germany' => self::GERMANY_LENGTH_KM,
        'earth' => self::EARTH_CIRCUMFERENCE_KM,
        'moon' => self::MOON_DISTANCE_KM,
    ];

    /**
     * Comparisons derived from the totals.
     *
     * A comparison that rounds away to nothing at its own precision is dropped rather than shown.
     * "0 coaches needed to move everyone" is not a small number, it is a false statement, and these
     * tiles are built to be read aloud.
     *
     * @return list<FunFact>
     */
    public function derived(EventStatistics $stats): array
    {
        $hours = $stats->worked->raw;
        if ($hours <= 0.0) {
            return [];
        }

        $facts = [];
        $distance = $hours * self::WALKING_PACE_KMH;

        $facts[] = new FunFact(
            icon: '👟',
            value: $distance,
            captionKey: 'manage.statistics.fun.distance.caption',
            basisKey: 'manage.statistics.fun.distance.basis',
            basisParams: ['%pace%' => (string) self::WALKING_PACE_KMH],
        );

        $facts[] = $this->distanceComparison($distance);

        $facts[] = new FunFact(
            icon: '⏳',
            value: $hours / self::HOURS_PER_YEAR,
            precision: 2,
            captionKey: 'manage.statistics.fun.nonstop.caption',
            basisKey: 'manage.statistics.fun.nonstop.basis',
        );

        $facts[] = new FunFact(
            icon: '💼',
            value: $hours / self::CAREER_HOURS,
            precision: 2,
            captionKey: 'manage.statistics.fun.careers.caption',
            basisKey: 'manage.statistics.fun.careers.basis',
        );

        $eventDays = $stats->window->days();
        if ($eventDays !== null && $eventDays >= 1.0) {
            $facts[] = new FunFact(
                icon: '🧑‍💼',
                value: $hours / (self::FULL_WORKING_DAY_HOURS * $eventDays),
                captionKey: 'manage.statistics.fun.fulltime.caption',
                basisKey: 'manage.statistics.fun.fulltime.basis',
                basisParams: ['%days%' => number_format($eventDays, 1)],
            );
        }

        $facts[] = new FunFact(
            icon: '🎬',
            value: $hours / self::FEATURE_FILM_HOURS,
            captionKey: 'manage.statistics.fun.films.caption',
            basisKey: 'manage.statistics.fun.films.basis',
        );

        $facts[] = new FunFact(
            icon: '🍌',
            value: $hours * self::KCAL_PER_HOUR / self::BANANA_KCAL,
            captionKey: 'manage.statistics.fun.bananas.caption',
            basisKey: 'manage.statistics.fun.bananas.basis',
            basisParams: ['%kcal%' => (string) (int) self::KCAL_PER_HOUR],
        );

        if ($stats->usersActive > 0) {
            $facts[] = new FunFact(
                icon: '🚌',
                value: $stats->usersActive / self::COACH_SEATS,
                precision: 1,
                captionKey: 'manage.statistics.fun.coaches.caption',
                basisKey: 'manage.statistics.fun.coaches.basis',
                basisParams: ['%seats%' => (string) (int) self::COACH_SEATS],
            );
        }

        return array_values(array_filter($facts, static fn (FunFact $f): bool => round($f->value, $f->precision) > 0));
    }

    /**
     * The hand-counted figures, plus the comparisons a known tally makes possible.
     *
     * @return list<FunFact>
     */
    public function tallies(Tallies $tallies, EventStatistics $stats): array
    {
        $facts = [];

        foreach ($tallies->known as $slug => $amount) {
            $facts[] = new FunFact(
                icon: TallyCatalog::icon($slug),
                value: $amount,
                captionKey: 'manage.statistics.tally.'.$slug.'.caption',
            );
        }

        $coffee = $tallies->get('coffee');
        if ($coffee !== null && $coffee > 0) {
            $litres = $coffee * self::COFFEE_CUP_LITRES;
            $facts[] = new FunFact(
                icon: '🛁',
                value: $litres / self::BATHTUB_LITRES,
                precision: 1,
                captionKey: 'manage.statistics.fun.coffee_bathtubs.caption',
                basisKey: 'manage.statistics.fun.coffee_bathtubs.basis',
                basisParams: [
                    '%litres%' => number_format($litres, 0),
                    '%cup%' => (string) self::COFFEE_CUP_LITRES,
                ],
            );
            $facts[] = new FunFact(
                icon: '⚡',
                value: $coffee * self::COFFEE_CAFFEINE_MG / 1000,
                precision: 1,
                captionKey: 'manage.statistics.fun.caffeine.caption',
                basisKey: 'manage.statistics.fun.caffeine.basis',
                basisParams: ['%mg%' => (string) (int) self::COFFEE_CAFFEINE_MG],
            );
        }

        foreach ($tallies->custom as $row) {
            $facts[] = new FunFact(
                icon: '✨',
                value: $row['amount'],
                literalCaption: $row['label'],
            );
        }

        if ($tallies->hourlyRate !== null && $stats->worked->raw > 0.0) {
            $facts[] = new FunFact(
                icon: '💶',
                value: $stats->worked->raw * $tallies->hourlyRate,
                captionKey: 'manage.statistics.fun.value.caption',
                captionParams: ['%currency%' => $tallies->currency],
                basisKey: 'manage.statistics.fun.value.basis',
                basisParams: [
                    '%rate%' => number_format($tallies->hourlyRate, 2),
                    '%currency%' => $tallies->currency,
                ],
            );
        }

        return $facts;
    }

    private function distanceComparison(float $distanceKm): FunFact
    {
        $chosen = 'marathon';
        foreach (self::DISTANCE_SCALE as $key => $reference) {
            if ($distanceKm >= $reference) {
                $chosen = $key;
            }
        }

        $reference = self::DISTANCE_SCALE[$chosen];

        return new FunFact(
            icon: '🌍',
            value: $distanceKm / $reference,
            precision: 2,
            captionKey: 'manage.statistics.fun.scale.'.$chosen.'.caption',
            basisKey: 'manage.statistics.fun.scale.basis',
            basisParams: ['%km%' => number_format($reference, 0)],
        );
    }
}
