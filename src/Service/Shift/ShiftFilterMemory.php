<?php

namespace App\Service\Shift;

use App\Entity\Settings;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Remembers the filters somebody last chose on a shift screen, so a page they come back to looks
 * the way they left it.
 *
 * Stored against the account rather than the session, so the choice follows them from the phone
 * they browse on to the laptop they plan on.
 *
 * Three arrivals have to be told apart, and getting this wrong is what makes filter memory feel
 * broken rather than helpful:
 *
 *  - The URL carries filters. It is the truth, whether typed, bookmarked or shared, and a link
 *    somebody sends must never be overridden by what the recipient last looked at.
 *  - The URL carries the form's marker and nothing else. Every box was unticked on purpose, so the
 *    remembered set is emptied rather than reapplied. Without the marker this is indistinguishable
 *    from a plain arrival, because an unticked checkbox submits nothing at all.
 *  - The URL carries nothing. A plain arrival, so the remembered filters are restored.
 *
 * The day is deliberately never remembered. It is the one filter whose meaning expires: somebody
 * returning the next morning would land on yesterday, see nothing, and reasonably conclude the
 * shifts had gone.
 */
final class ShiftFilterMemory
{
    /** The hidden field every filter form submits, which is what makes "all off" distinguishable. */
    public const MARKER = 'f';

    /** Volunteer shift browsing, `/shifts`. */
    public const SURFACE_BROWSE = 'browse';

    /** The staff apply grid, `/manage-shifts/apply`. */
    public const SURFACE_APPLY = 'apply';

    /**
     * What each surface is allowed to remember, and how each value is read back.
     *
     * Whitelisted rather than stored wholesale: this is user input on a round trip through the
     * database, and a stored blob that is replayed into a query without checking is how a filter
     * becomes an injection point. `uuid` and `uuids` are validated on the way out as well, so a
     * location deleted since it was chosen simply drops out.
     *
     * `scope` is normalised to what it means rather than kept verbatim. Its checkbox submits an
     * empty value when unticked, which the grid reads as "not my departments only"; storing that
     * emptiness as-is would drop it and silently retick the box on the next visit.
     */
    private const SHAPES = [
        self::SURFACE_BROWSE => [
            'location' => 'uuid',
            'type' => 'uuid',
            'available' => 'bool',
            'mine' => 'bool',
        ],
        self::SURFACE_APPLY => [
            'scope' => 'scope',
            'departments' => 'uuids',
        ],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** Whether this request is stating its own filters, in which case nothing is restored. */
    public function requestCarriesFilters(array $query, string $surface): bool
    {
        if (isset($query[self::MARKER])) {
            return true;
        }

        foreach (array_keys(self::SHAPES[$surface]) as $name) {
            if (isset($query[$name]) && $query[$name] !== '' && $query[$name] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The filters to apply, given what the request carries and what the user last chose.
     *
     * @return array<string, mixed> query-shaped values, ready to be read as though they had arrived
     *                              in the URL
     */
    public function resolve(User $user, string $surface, array $query): array
    {
        if ($this->requestCarriesFilters($query, $surface)) {
            $this->remember($user, $surface, $query);

            return $this->clean($surface, $query);
        }

        return $this->recall($user, $surface);
    }

    /** @return array<string, mixed> */
    public function recall(User $user, string $surface): array
    {
        $stored = $user->getSettings()?->getShiftFilters()[$surface] ?? [];

        return \is_array($stored) ? $this->clean($surface, $stored) : [];
    }

    public function remember(User $user, string $surface, array $query): void
    {
        $settings = $user->getSettings();
        if (!$settings instanceof Settings) {
            return;
        }

        $all = $settings->getShiftFilters();
        $all[$surface] = $this->clean($surface, $query);
        $settings->setShiftFilters($all);
        $this->em->flush();
    }

    public function forget(User $user, string $surface): void
    {
        $this->remember($user, $surface, []);
    }

    /**
     * Keep only what this surface may remember, in the type it must be.
     *
     * @return array<string, mixed>
     */
    private function clean(string $surface, array $values): array
    {
        $clean = [];
        foreach (self::SHAPES[$surface] as $name => $kind) {
            if (!isset($values[$name])) {
                continue;
            }
            $value = $values[$name];

            $kept = match ($kind) {
                'bool' => $this->boolean($value) ? '1' : null,
                'uuid' => \is_string($value) && \Symfony\Component\Uid\Uuid::isValid($value) ? $value : null,
                'uuids' => $this->uuids($value),
                'scope' => \is_string($value) ? ($value === 'mine' ? 'mine' : 'all') : null,
                default => null,
            };

            if ($kept !== null && $kept !== []) {
                $clean[$name] = $kept;
            }
        }

        return $clean;
    }

    private function boolean(mixed $value): bool
    {
        return \in_array($value, [true, 1, '1', 'true', 'on'], true);
    }

    /** @return string[] */
    private function uuids(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $uuid): bool => \is_string($uuid) && \Symfony\Component\Uid\Uuid::isValid($uuid),
        ));
    }
}
