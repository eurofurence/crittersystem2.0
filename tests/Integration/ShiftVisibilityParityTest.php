<?php

namespace App\Tests\Integration;

use App\Entity\Shift;
use App\Entity\User;
use App\Enum\ShiftAudience;
use App\Enum\ShiftState;
use App\Repository\ShiftRepository;
use App\Service\Shift\ShiftVisibilityResolver;
use App\Tests\DatabaseTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The audience rule is written twice: once per loaded shift in
 * {@see ShiftVisibilityResolver::isVisibleTo()}, and once as a query predicate in
 * {@see ShiftVisibilityResolver::applyVisibilityFor()}, which is what lets a list be narrowed before
 * it is capped instead of after.
 *
 * Two expressions of one rule drift. This asserts they cannot: over every audience, both publication
 * states and every shape of viewer, the query must return exactly the shifts the per-shift decision
 * admits. A divergence in either direction is a defect - the query returning more is a leak, the
 * query returning less silently hides shifts a volunteer is entitled to.
 */
final class ShiftVisibilityParityTest extends DatabaseTestCase
{
    private ShiftScenario $scenario;
    private ShiftVisibilityResolver $visibility;
    private ShiftRepository $shifts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = new ShiftScenario(
            $this->em,
            static::getContainer()->get(UserPasswordHasherInterface::class),
        );
        $this->visibility = static::getContainer()->get(ShiftVisibilityResolver::class);
        $this->shifts = static::getContainer()->get(ShiftRepository::class);
    }

    /**
     * One shift per audience and publication state, so the matrix covers the draft rule as well as
     * every audience.
     *
     * @return array<string, Shift>
     */
    private function seedShiftMatrix(): array
    {
        $shifts = [];
        $hour = 8;
        foreach (ShiftAudience::cases() as $audience) {
            foreach ([ShiftState::PUBLISHED, ShiftState::DRAFT] as $state) {
                $key = $audience->value.'/'.$state->value;
                $shift = $this->scenario->shift($key, 'tomorrow '.sprintf('%02d:00', $hour++), '+1 hour', 2, $state);
                $shift->setAudience($audience);
                $shifts[$key] = $shift;
            }
        }
        $this->em->flush();

        return $shifts;
    }

    /** @return list<string> titles the query admits for this viewer */
    private function queryTitles(?User $viewer): array
    {
        $qb = $this->shifts->createQueryBuilder('s')->orderBy('s.startsAt', 'ASC');
        $this->visibility->applyVisibilityFor($qb, $viewer, $this->visibility->memberDepartmentIds($viewer));

        return array_map(static fn (Shift $s): string => $s->getTitle(), $qb->getQuery()->getResult());
    }

    /**
     * @param array<string, Shift> $shifts
     *
     * @return list<string> titles the per-shift decision admits for this viewer
     */
    private function decisionTitles(array $shifts, ?User $viewer): array
    {
        $titles = [];
        foreach ($shifts as $shift) {
            if ($this->visibility->isVisibleTo($shift, $viewer)) {
                $titles[] = $shift->getTitle();
            }
        }
        sort($titles);

        return $titles;
    }

    /**
     * @param array<string, Shift> $shifts
     */
    private function assertParity(array $shifts, ?User $viewer, string $who): void
    {
        $fromQuery = $this->queryTitles($viewer);
        sort($fromQuery);

        self::assertSame(
            $this->decisionTitles($shifts, $viewer),
            $fromQuery,
            sprintf('the query and the per-shift decision disagree for %s', $who),
        );
    }

    public function testAnAnonymousViewerSeesPublishedPublicShiftsOnly(): void
    {
        $shifts = $this->seedShiftMatrix();

        $this->assertParity($shifts, null, 'an anonymous viewer');
        self::assertSame(['public_volunteer/published'], $this->queryTitles(null));
    }

    public function testAPlainVolunteerSeesPublishedPublicShiftsOnly(): void
    {
        $shifts = $this->seedShiftMatrix();
        $volunteer = $this->scenario->user();

        $this->assertParity($shifts, $volunteer, 'a plain volunteer');
        self::assertSame(['public_volunteer/published'], $this->queryTitles($volunteer));
    }

    public function testStaffOutsideTheDepartmentSeeAllStaffButNotDepartmentStaff(): void
    {
        $shifts = $this->seedShiftMatrix();
        $staff = $this->scenario->user(['shift:view'], null, 'ROLE_STAFF');

        $this->assertParity($shifts, $staff, 'staff outside the department');

        $titles = $this->queryTitles($staff);
        self::assertContains('all_staff/published', $titles);
        self::assertNotContains('department_staff/published', $titles, 'department_staff is the audience that means department-only');
    }

    public function testStaffInsideTheDepartmentAlsoSeeDepartmentStaff(): void
    {
        $shifts = $this->seedShiftMatrix();
        $staff = $this->scenario->departmentMember($this->scenario->user(['shift:view'], null, 'ROLE_STAFF'));

        $this->assertParity($shifts, $staff, 'staff inside the department');
        self::assertContains('department_staff/published', $this->queryTitles($staff));
    }

    public function testDepartmentMembershipWithoutStaffIsNotEnough(): void
    {
        $shifts = $this->seedShiftMatrix();
        $member = $this->scenario->departmentMember($this->scenario->user());

        $this->assertParity($shifts, $member, 'a non-staff department member');
        self::assertSame(['public_volunteer/published'], $this->queryTitles($member));
    }

    public function testAnInviteOnlyShiftIsVisibleOnlyToSomebodyHoldingAnEntry(): void
    {
        $shifts = $this->seedShiftMatrix();
        $invited = $this->scenario->user();
        $this->scenario->signUp($invited, $shifts['invite_only/published']);
        $this->em->flush();

        $this->assertParity($shifts, $invited, 'an invited volunteer');

        $titles = $this->queryTitles($invited);
        self::assertContains('invite_only/published', $titles);
        self::assertNotContains('invite_only/draft', $titles, 'an entry does not make a draft visible');
    }

    /** An entry on one invite-only shift must not reveal another. */
    public function testAnEntryOnOneInviteOnlyShiftDoesNotRevealAnother(): void
    {
        $shifts = $this->seedShiftMatrix();
        $invited = $this->scenario->user();
        $this->scenario->signUp($invited, $shifts['invite_only/published']);

        $other = $this->scenario->shift('another invite-only', 'tomorrow 20:00');
        $other->setAudience(ShiftAudience::INVITE_ONLY);
        $this->em->flush();

        $this->assertParity($shifts + ['other' => $other], $invited, 'an invited volunteer');
        self::assertNotContains('another invite-only', $this->queryTitles($invited));
    }
}
