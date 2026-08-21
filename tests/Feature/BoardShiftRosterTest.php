<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The board's per-shift roster dialog, and marking somebody absent from it.
 *
 * Who may read a shift's roster is decided once, by ShiftDossierPresenter, and the board asks it
 * rather than answering again: `board:view` is a department-wide grant that does not by itself make
 * somebody answerable for a shift. Marking a no-show is a further question, because it can lock the
 * volunteer's account through the automatic ban, so it needs `shift:manage` for that shift - which
 * is exactly what the action enforces.
 */
final class BoardShiftRosterTest extends DatabaseWebTestCase
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

    /** @param list<string> $privileges */
    private function login(array $privileges = ['board:view'], ?Department $scope = null): User
    {
        $suffix = bin2hex(random_bytes(4));
        $group = new Group('Board '.$suffix, 'board-'.$suffix, 'ROLE_STAFF');
        foreach ($privileges as $name) {
            $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => $name]) ?? new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $user = new User();
        $user->setName('board-'.$suffix)->setEmail('board-'.$suffix.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'secret123'));
        $user->completeOnboarding();
        $this->em->persist($user->assignGroup($group, $scope ?? $this->scenario->department));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);

        return $user;
    }

    private function shiftToday(string $title = 'Door'): Shift
    {
        $day = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $shift = $this->scenario->shift($title, 'today 00:00', '+1 hour', 2);
        $shift->setStartsAt($day->modify('+6 hours'))->setEndsAt($day->modify('+9 hours'));
        $this->em->flush();

        return $shift;
    }

    private function rosterUrl(Shift $shift, ?Department $department = null): string
    {
        return sprintf(
            '/board/%s/%s/shift/%s/roster',
            ($department ?? $this->scenario->department)->getUuid(),
            (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->format('Y-m-d'),
            $shift->getUuid(),
        );
    }

    public function testTheShiftsViewOffersTheRoster(): void
    {
        $shift = $this->shiftToday();
        $this->login(['board:view', 'shift:manage']);

        $crawler = $this->client->request('GET', sprintf(
            '/board/%s/%s?view=shifts',
            $this->scenario->department->getUuid(),
            (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->format('Y-m-d'),
        ));

        self::assertResponseIsSuccessful();
        self::assertSame(
            [$this->rosterUrl($shift)],
            $crawler->filter('[data-board-roster-link]')->extract(['href']),
        );
    }

    public function testTheRosterListsWhoIsOnTheShift(): void
    {
        $shift = $this->shiftToday();
        $volunteer = $this->scenario->user(memberOf: $this->scenario->type);
        $this->scenario->signUp($volunteer, $shift);

        $this->login(['board:view', 'shift:manage']);

        $crawler = $this->client->request('GET', $this->rosterUrl($shift));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($volunteer->getName(), $crawler->filter('turbo-frame#board-modal')->text());
    }

    /**
     * `board:view` reaches the board for a whole department without making the viewer answerable for
     * any shift in it. The roster is the shift dossier's privileged tier, so it is refused here, and
     * refused with a 404 so the board does not confirm what it will not show.
     */
    public function testTheRosterIsNotFoundForABoardViewerWithNoShiftPrivilege(): void
    {
        $shift = $this->shiftToday();
        $volunteer = $this->scenario->user(memberOf: $this->scenario->type);
        $this->scenario->signUp($volunteer, $shift);

        $this->login(['board:view']);

        $this->client->request('GET', $this->rosterUrl($shift));

        self::assertResponseStatusCodeSame(404);
    }

    /** A viewer refused the roster is not offered the link to it either. */
    public function testTheShiftsViewWithholdsTheRosterLinkFromThatViewer(): void
    {
        $this->shiftToday();
        $this->login(['board:view']);

        $crawler = $this->client->request('GET', sprintf(
            '/board/%s/%s?view=shifts',
            $this->scenario->department->getUuid(),
            (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->format('Y-m-d'),
        ));

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('[data-board-roster-link]')->count());
    }

    /**
     * A manager answerable for shifts in two departments must still not read one department's roster
     * through the other department's board: the shift is re-checked against the department in the
     * URL, so the privilege alone is not enough.
     */
    public function testAShiftFromAnotherDepartmentIsNotFound(): void
    {
        $other = new Department('Security', 'security-'.bin2hex(random_bytes(3)));
        $this->em->persist($other);
        $this->em->flush();

        $shift = $this->shiftToday();
        $shift->setDepartment($other);
        $this->em->flush();

        $manager = $this->login(['board:view', 'shift:manage']);
        $this->em->persist($manager->assignGroup($manager->getActiveAssignments()[0]->getGroup(), $other));
        $this->em->flush();

        $this->client->request('GET', $this->rosterUrl($shift));

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * `assignment:manage` opens the roster, because somebody who staffs a shift is answerable for it.
     * The no-show action enforces `shift:manage` and would refuse, so the button is withheld.
     */
    public function testTheNoShowButtonIsWithheldWithoutShiftManage(): void
    {
        $shift = $this->shiftToday();
        $volunteer = $this->scenario->user(memberOf: $this->scenario->type);
        $this->scenario->signUp($volunteer, $shift);

        $this->login(['board:view', 'assignment:manage']);

        $crawler = $this->client->request('GET', $this->rosterUrl($shift));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($volunteer->getName(), $crawler->filter('turbo-frame#board-modal')->text());
        self::assertSame(0, $crawler->filter('form[action*="/noshow"]')->count());
    }

    /**
     * The kit's Button renders `type="button"` unless the caller overrides it, so a confirmation
     * whose action is not a submit opens, is confirmed, and records nothing. BrowserKit posts a form
     * node whatever its buttons say, so only reading the type catches it.
     */
    public function testTheNoShowConfirmationButtonSubmitsTheForm(): void
    {
        $shift = $this->shiftToday();
        $volunteer = $this->scenario->user(memberOf: $this->scenario->type);
        $this->scenario->signUp($volunteer, $shift);

        $this->login(['board:view', 'shift:manage']);

        $crawler = $this->client->request('GET', $this->rosterUrl($shift));

        $types = $crawler->filter('form[action*="/noshow"] button')->extract(['type']);
        self::assertNotEmpty($types, 'the roster should offer a no-show form');
        self::assertSame(['submit'], array_values(array_unique($types)));
    }

    /**
     * The form posts from inside the modal host frame, so it must drive a top-level visit. Left to
     * target its own frame, Turbo would swap the empty host into the board and leave the screen
     * showing the staffing it just changed.
     */
    public function testTheNoShowFormBreaksOutOfTheModalFrame(): void
    {
        $shift = $this->shiftToday();
        $volunteer = $this->scenario->user(memberOf: $this->scenario->type);
        $this->scenario->signUp($volunteer, $shift);

        $this->login(['board:view', 'shift:manage']);

        $crawler = $this->client->request('GET', $this->rosterUrl($shift));

        self::assertSame(
            ['_top'],
            $crawler->filter('form[action*="/noshow"]')->extract(['data-turbo-frame']),
        );
    }

    public function testMarkingANoShowFromTheBoardReturnsToTheBoard(): void
    {
        $shift = $this->shiftToday();
        $volunteer = $this->scenario->user(memberOf: $this->scenario->type);
        $entry = $this->scenario->signUp($volunteer, $shift);

        $this->login(['board:view', 'shift:manage']);

        $crawler = $this->client->request('GET', $this->rosterUrl($shift));
        $this->client->submit($crawler->filter('form[action*="/noshow"]')->form());

        self::assertResponseRedirects(sprintf(
            '/board/%s/%s?view=shifts',
            $this->scenario->department->getUuid(),
            (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->format('Y-m-d'),
        ));

        $this->em->clear();
        self::assertTrue($this->em->getRepository(\App\Entity\ShiftEntry::class)->find($entry->getId())->isNoshow());
    }
}
