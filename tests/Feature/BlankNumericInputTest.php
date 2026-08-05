<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\PositionGroup;
use App\Entity\Privilege;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Optional form fields left blank must fall back to their default, not answer 400.
 *
 * A submitted form posts every named field, so a cleared `<input type="number">` and an
 * `<option value="">` placeholder both arrive as an empty string. `InputBag::getInt()` refuses that
 * as malformed, and because the value is *present* its `$default` argument never applies - so the
 * default is unreachable for precisely the case it was written for.
 *
 * Each test posts what a browser actually sends. See docs/tasks/input-bag-empty-value-audit.md.
 */
final class BlankNumericInputTest extends DatabaseWebTestCase
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

    /** @param string[] $privileges */
    private function manager(array $privileges, ?Department $department = null): User
    {
        $suffix = bin2hex(random_bytes(4));
        $group = new Group('Mgr '.$suffix, 'mgr-'.$suffix, 'ROLE_STAFF');
        foreach ($privileges as $name) {
            $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => $name]) ?? new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $user = new User();
        $user->setName('mgr-'.$suffix)->setEmail('mgr-'.$suffix.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $user->completeOnboarding();
        if ($department !== null) {
            $this->em->persist($user->assignGroup($group, $department));
        } else {
            $user->addGroup($group);
        }
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function plannerToken(Department $department): string
    {
        $crawler = $this->client->request('GET', '/manage-shifts/planner?department='.$department->getUuid());

        return $crawler->filter('.planner-panel input[name="_token"]')->first()->attr('value');
    }

    /**
     * The matrix guards with its own `matrix_edit` token, not the planner's. Minted by rendering a
     * page, because a CSRF token lives in the session and cannot be built outside a request.
     */
    private function matrixToken(Department $department): string
    {
        $crawler = $this->client->request('GET', '/manage-shifts/matrix?department='.$department->getUuid());
        self::assertResponseIsSuccessful();

        return $crawler->filter('[data-matrix-token-value]')->attr('data-matrix-token-value');
    }

    /** A cleared capacity box when creating a Named Position. */
    public function testMatrixPositionCapacityMayBeBlank(): void
    {
        $department = $this->scenario->department;
        $positionGroup = new PositionGroup($department, 'Stage');
        $this->em->persist($positionGroup);
        $this->em->flush();

        $this->client->loginUser($this->manager(['shift:manage', 'shift:assign'], $department));
        $this->client->request('POST', '/manage-shifts/matrix/position', [
            '_token' => $this->matrixToken($department),
            'group' => (string) $positionGroup->getId(),
            'name' => 'Spot',
            'capacity' => '',
        ]);

        self::assertResponseIsSuccessful();
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['ok']);
    }

    /**
     * The planner's task picker offers an empty placeholder, so an unchosen task arrives as "". The
     * server has its own message for that, and it has to be reachable rather than resting on the
     * HTML `required` attribute that normally stops it.
     */
    public function testAnUnchosenShiftTaskIsReportedRatherThanRefused(): void
    {
        $department = $this->scenario->department;
        $this->client->loginUser($this->manager(['shift:manage'], $department));

        $this->client->request('POST', '/manage-shifts/planner/create', [
            '_token' => $this->plannerToken($department),
            'department' => (string) $department->getUuid(),
            'start' => '2036-06-01 10:00',
            'end' => '2036-06-01 12:00',
            'task' => '',
            'location' => '0',
        ]);

        // A readable refusal (the planner's own 422), not a 400 BadRequestException.
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertFalse($data['ok']);
        self::assertStringContainsString('shift task', strtolower((string) $data['error']));
    }

    /** A hand-edited URL with a blank page number shows the first page instead of answering 400. */
    public function testABlankPaginationParameterFallsBackToTheFirstPage(): void
    {
        $department = $this->scenario->department;
        $user = $this->manager(['department:view'], $department);
        $this->client->loginUser($user);

        $this->client->request('GET', '/departments/'.$department->getUuid().'?managers_page=&staff_page=&nonstaff_page=');

        self::assertResponseIsSuccessful();
    }

    /** A cleared slots box on the help-call form. */
    public function testHelpCallSlotsMayBeBlank(): void
    {
        // A running shift, so the call is actually allowed and the request reaches the slots read.
        $shift = $this->scenario->shift('Gate', '-1 hour', '+3 hours');
        // The super-privilege, unscoped: authorization is not what this test is about, and scoping it
        // would only make the setup another thing that can go wrong.
        $user = $this->manager(['global:admin']);
        $this->scenario->departmentMember($user);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/manage-shifts/shift/'.$shift->getUuid().'/staffing');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action*="/calls/trigger"]');
        self::assertGreaterThan(0, $form->count(), 'The call form must be on the page for this to test anything.');

        $this->client->request('POST', '/calls/trigger', [
            '_token' => $form->filter('input[name="_token"]')->attr('value'),
            'shift' => (string) $shift->getId(),
            'slots' => '',
        ]);

        // Redirects back to the staffing page; a blank slots box is not a malformed request.
        self::assertResponseRedirects();
        self::assertSame(
            1,
            $this->em->getRepository(\App\Entity\HelpCall::class)->count(['shift' => $shift->getId()]),
            'A blank slots box falls back to the single slot the controller intends.',
        );
    }
}
