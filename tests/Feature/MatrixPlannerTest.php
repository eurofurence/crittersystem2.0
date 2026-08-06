<?php

namespace App\Tests\Feature;

use App\Entity\NamedPosition;
use App\Entity\Shift;
use App\Entity\ShiftPosition;
use App\Entity\ShiftPositionAssignment;
use App\Entity\User;
use App\Service\Shift\PositionService;
use App\Tests\DatabaseWebTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The matrix planner: staffing positions from the grid, and keeping the page current.
 *
 * The editing endpoints existed but nothing in the page reached them, and mutations left the page
 * showing stale data until a manual reload. Both are covered here - the cells must expose the
 * identifiers the endpoints resolve by, and the page must serve a content region the client can
 * swap in after a change.
 */
final class MatrixPlannerTest extends DatabaseWebTestCase
{
    private ShiftScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = new ShiftScenario($this->em, static::getContainer()->get(UserPasswordHasherInterface::class));
    }

    private function positions(): PositionService
    {
        return static::getContainer()->get(PositionService::class);
    }

    /** A department with one position column, and one published shift with that position enabled. */
    private function matrix(bool $enable = true, int $capacity = 1): array
    {
        $group = $this->positions()->createGroup($this->scenario->department, 'Light');
        $position = $this->positions()->createPosition($group, 'FOH', $capacity);
        $shift = $this->scenario->shift('Matrix Shift', 'tomorrow 10:00');
        $shiftPosition = $enable ? $this->positions()->enablePosition($shift, $position, true) : null;
        $this->em->flush();

        return [$shift, $position, $shiftPosition];
    }

    private function manager(array $extra = []): User
    {
        return $this->scenario->user(
            privileges: array_merge(['shift:view', 'shift:manage'], $extra),
            memberOf: $this->scenario->type,
            role: 'ROLE_STAFF',
        );
    }

    private function token(): string
    {
        $crawler = $this->client->request('GET', '/manage-shifts/matrix?department='.$this->scenario->department->getUuid());

        return $crawler->filter('[data-matrix-token-value]')->attr('data-matrix-token-value');
    }

    public function testTheMatrixRendersEachCellAsAnActionableControl(): void
    {
        [$shift, , $shiftPosition] = $this->matrix();
        $this->client->loginUser($this->manager(['shift:assign']));

        $crawler = $this->client->request('GET', '/manage-shifts/matrix?department='.$this->scenario->department->getUuid());

        self::assertResponseIsSuccessful();
        $cell = $crawler->filter('.matrix-cell-button');
        self::assertGreaterThan(0, $cell->count(), 'every cell must be reachable - a read-only matrix cannot be staffed');

        // The identifiers the editing endpoints resolve by must reach the client.
        self::assertSame((string) $shiftPosition->getUuid(), $cell->attr('data-shift-position-uuid'));
        self::assertSame((string) $shift->getUuid(), $cell->attr('data-shift'));
        self::assertNotEmpty($cell->attr('data-assignments'));
    }

    public function testTheAssignmentPickerSearchesAllUsersViaTypeAhead(): void
    {
        $this->matrix();
        $this->client->loginUser($this->manager(['shift:assign']));

        $this->client->request('GET', '/manage-shifts/matrix?department='.$this->scenario->department->getUuid());

        self::assertResponseIsSuccessful();
        // The cell editor clones this picker: it must be the shared type-ahead wired to the matrix
        // user-search endpoint, not a fixed members-only dropdown that leaves empty departments unable
        // to staff anyone.
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('id="matrix-user-picker"', $html);
        self::assertStringContainsString('data-controller="user-select"', $html);
        self::assertStringContainsString('/manage-shifts/matrix/users', $html);
    }

    public function testTheUserSearchFindsDepartmentMembers(): void
    {
        $this->matrix();
        $member = $this->scenario->user(memberOf: $this->scenario->type);
        $this->scenario->departmentMember($member);
        $this->client->loginUser($this->manager(['shift:assign']));

        $this->client->request('GET', '/manage-shifts/matrix/users', [
            'department' => (string) $this->scenario->department->getUuid(),
            'q' => $member->getName(),
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertContains($member->getName(), array_column($data['results'], 'name'));
    }

    public function testTheContentRegionIsServedSoTheClientCanRefreshWithoutAFullReload(): void
    {
        $this->matrix();
        $this->client->loginUser($this->manager());

        $crawler = $this->client->request('GET', '/manage-shifts/matrix?department='.$this->scenario->department->getUuid());

        self::assertCount(1, $crawler->filter('[data-matrix-target="content"]'));
        // The structure forms live inside it, so a new group also refreshes their dropdown.
        self::assertGreaterThan(0, $crawler->filter('[data-matrix-target="content"] form[action*="/matrix/position"]')->count());
    }

    public function testAManagerCanAssignAVolunteerToAPosition(): void
    {
        [, , $shiftPosition] = $this->matrix();
        $manager = $this->manager(['shift:assign']);
        $this->client->loginUser($manager);

        $volunteer = $this->scenario->user(memberOf: $this->scenario->type);

        $this->client->request('POST', '/manage-shifts/matrix/shift-position/'.$shiftPosition->getUuid().'/assign', [
            '_token' => $this->token(),
            'user' => $volunteer->getUuid(),
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $fresh = $this->em->getRepository(ShiftPosition::class)->find($shiftPosition->getId());
        self::assertCount(1, $fresh->getAssignments());
        self::assertSame($volunteer->getId(), $fresh->getAssignments()->first()->getUser()->getId());
    }

    public function testAssigningBeyondCapacityIsRefused(): void
    {
        [, , $shiftPosition] = $this->matrix(capacity: 1);
        $this->client->loginUser($this->manager(['shift:assign']));
        $token = $this->token();

        $first = $this->scenario->user(memberOf: $this->scenario->type);
        $second = $this->scenario->user(memberOf: $this->scenario->type);

        $this->client->request('POST', '/manage-shifts/matrix/shift-position/'.$shiftPosition->getUuid().'/assign', [
            '_token' => $token, 'user' => $first->getUuid(),
        ]);
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/manage-shifts/matrix/shift-position/'.$shiftPosition->getUuid().'/assign', [
            '_token' => $token, 'user' => $second->getUuid(),
        ]);

        self::assertResponseStatusCodeSame(409, 'a position of capacity 1 must not take a second volunteer');
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(ShiftPosition::class)->find($shiftPosition->getId())->getAssignments());
    }

    public function testAManagerCanUnassignAVolunteer(): void
    {
        [, , $shiftPosition] = $this->matrix();
        $this->client->loginUser($this->manager(['shift:assign']));
        $token = $this->token();

        $volunteer = $this->scenario->user(memberOf: $this->scenario->type);
        $this->client->request('POST', '/manage-shifts/matrix/shift-position/'.$shiftPosition->getUuid().'/assign', [
            '_token' => $token, 'user' => $volunteer->getUuid(),
        ]);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $assignment = $this->em->getRepository(ShiftPositionAssignment::class)->findOneBy([]);
        self::assertNotNull($assignment);

        $this->client->request('POST', '/manage-shifts/matrix/assignment/'.$assignment->getUuid().'/unassign', [
            '_token' => $token,
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(ShiftPosition::class)->find($shiftPosition->getId())->getAssignments());
    }

    public function testAPositionCanBeEnabledOnAShiftFromAnEmptyCell(): void
    {
        [$shift, $position] = $this->matrix(enable: false);
        $this->client->loginUser($this->manager(['shift:assign']));

        $this->client->request(
            'POST',
            '/manage-shifts/matrix/shift/'.$shift->getUuid().'/position/'.$position->getUuid().'/enable',
            ['_token' => $this->token(), 'required' => '1'],
        );

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $fresh = $this->em->getRepository(Shift::class)->find($shift->getId());
        self::assertCount(1, $fresh->getShiftPositions());
    }

    public function testAssignmentRequiresTheAssignPrivilege(): void
    {
        [, , $shiftPosition] = $this->matrix();
        $this->client->loginUser($this->manager()); // shift:manage only - may edit structure, not staff
        $token = $this->token();

        $volunteer = $this->scenario->user(memberOf: $this->scenario->type);
        $this->client->request('POST', '/manage-shifts/matrix/shift-position/'.$shiftPosition->getUuid().'/assign', [
            '_token' => $token, 'user' => $volunteer->getUuid(),
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(ShiftPosition::class)->find($shiftPosition->getId())->getAssignments());
    }

    public function testAssignmentIsRefusedWithoutAValidCsrfToken(): void
    {
        [, , $shiftPosition] = $this->matrix();
        $this->client->loginUser($this->manager(['shift:assign']));

        $volunteer = $this->scenario->user(memberOf: $this->scenario->type);
        $this->client->request('POST', '/manage-shifts/matrix/shift-position/'.$shiftPosition->getUuid().'/assign', [
            '_token' => 'not-a-real-token',
            'user' => $volunteer->getUuid(),
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(ShiftPosition::class)->find($shiftPosition->getId())->getAssignments());
    }

    public function testTheMatrixIsClosedToAVolunteer(): void
    {
        $this->matrix();
        $this->client->loginUser($this->scenario->user()); // no shift:manage

        $this->client->request('GET', '/manage-shifts/matrix?department='.$this->scenario->department->getUuid());

        self::assertResponseStatusCodeSame(403);
    }
}
