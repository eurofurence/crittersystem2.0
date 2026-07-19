<?php

namespace App\Tests\Feature;

use App\Enum\ShiftAudience;
use App\Enum\ShiftState;
use App\Tests\DatabaseWebTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * /api/v0-beta is public and unauthenticated. It must expose only published,
 * public-audience shifts, and must identify records by their public UUID - the
 * identifier its own routes accept.
 */
final class ApiV0VisibilityTest extends DatabaseWebTestCase
{
    private ShiftScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = new ShiftScenario(
            $this->em,
            static::getContainer()->get(UserPasswordHasherInterface::class),
        );
    }

    /** @return array<string, mixed> */
    private function get(string $uri): array
    {
        $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return string[] */
    private function titles(array $body): array
    {
        return array_column($body['data'], 'title');
    }

    public function testDraftShiftsAreNotPubliclyListed(): void
    {
        $this->scenario->shift('Published Shift');
        $this->scenario->shift('Draft Plan', 'tomorrow 10:00', '+2 hours', 2, ShiftState::DRAFT);

        $titles = $this->titles($this->get('/api/v0-beta/shifts'));

        self::assertContains('Published Shift', $titles);
        self::assertNotContains('Draft Plan', $titles, 'Unpublished planning must not be public');
    }

    public function testStaffOnlyShiftsAreNotPubliclyListed(): void
    {
        $this->scenario->shift('Public Shift');
        $staff = $this->scenario->shift('Staff Briefing');
        $staff->setAudience(ShiftAudience::ALL_STAFF);
        $this->em->flush();

        $titles = $this->titles($this->get('/api/v0-beta/shifts'));

        self::assertContains('Public Shift', $titles);
        self::assertNotContains('Staff Briefing', $titles);
    }

    public function testInviteOnlyShiftsAreNotPubliclyListed(): void
    {
        $invite = $this->scenario->shift('Secret Detail');
        $invite->setAudience(ShiftAudience::INVITE_ONLY);
        $this->em->flush();

        self::assertNotContains('Secret Detail', $this->titles($this->get('/api/v0-beta/shifts')));
    }

    public function testShiftsByLocationHideNonPublicShifts(): void
    {
        $this->scenario->shift('Public Shift');
        $staff = $this->scenario->shift('Staff Briefing');
        $staff->setAudience(ShiftAudience::DEPARTMENT_STAFF);
        $this->scenario->shift('Draft Plan', 'tomorrow 10:00', '+2 hours', 2, ShiftState::DRAFT);
        $this->em->flush();

        $titles = $this->titles($this->get('/api/v0-beta/locations/'.$this->scenario->location->getUuid().'/shifts'));

        self::assertSame(['Public Shift'], $titles);
    }

    public function testShiftsByShiftTaskHideNonPublicShifts(): void
    {
        $this->scenario->shift('Public Shift');
        $staff = $this->scenario->shift('Staff Briefing');
        $staff->setAudience(ShiftAudience::ALL_STAFF);
        $this->em->flush();

        $titles = $this->titles($this->get('/api/v0-beta/shifttypes/'.$this->scenario->task->getUuid().'/shifts'));

        self::assertSame(['Public Shift'], $titles);
    }

    /**
     * The routes accept a UUID, so the ids the API hands out must be UUIDs:
     * serialising the internal primary key returns an identifier that does not
     * work against the API's own URLs, and leaks the sequential key the public
     * UUID exists to hide.
     */
    public function testListedIdsAreTheUuidsTheRoutesAccept(): void
    {
        $this->scenario->shift('Public Shift');

        $location = $this->get('/api/v0-beta/locations')['data'][0];
        self::assertSame((string) $this->scenario->location->getUuid(), $location['id']);

        // The id round-trips against the API's own URL.
        $this->client->request('GET', '/api/v0-beta/locations/'.$location['id'].'/shifts');
        self::assertResponseIsSuccessful();

        $task = $this->get('/api/v0-beta/shifttypes')['data'][0];
        self::assertSame((string) $this->scenario->task->getUuid(), $task['id']);

        $type = $this->get('/api/v0-beta/volunteertypes')['data'][0];
        self::assertSame((string) $this->scenario->type->getUuid(), $type['id']);

        $shift = $this->get('/api/v0-beta/shifts')['data'][0];
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $shift['id']);
    }
}
