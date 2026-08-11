<?php

namespace App\Tests\Browser;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\NeededVolunteerType;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Enum\ShiftAudience;
use App\Enum\ShiftState;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The staff application grid in a real browser.
 *
 * The screen is a grid of buttons that fetch a dialog and apply from it. PHPUnit renders the markup
 * but never runs any of that, and this is exactly the shape of bug that shipped before: a Stimulus
 * target placed outside its controller element is not a target, so every click did nothing behind a
 * 200 and perfectly correct HTML.
 */
final class StaffApplyBrowserTest extends BrowserTestCase
{
    private const PASSWORD = 'secret123';

    private function seed(): Shift
    {
        $group = new Group('Staff', 'staff-'.bin2hex(random_bytes(2)), 'ROLE_STAFF');
        foreach (['manageshifts:view', 'shift:self', 'news:view'] as $name) {
            $privilege = new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('applicant')->setEmail('applicant@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);

        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $membership = new UserVolunteerType($user, $type);
        $membership->setConfirmedBy($user);
        $this->em->persist($membership);

        $department = new Department('Logistics', 'logistics');
        $this->em->persist($department);

        $shift = (new Shift())->setTitle('Gate')
            ->setStartsAt(new \DateTimeImmutable('+1 day 10:00'))
            ->setEndsAt(new \DateTimeImmutable('+1 day 12:00'))
            ->setDepartment($department)
            ->setAudience(ShiftAudience::ALL_STAFF)
            ->setState(ShiftState::PUBLISHED);
        $this->em->persist($shift);
        $need = new NeededVolunteerType($type, 2);
        $shift->addNeededVolunteerType($need);
        $this->em->persist($need);

        $this->em->flush();

        return $shift;
    }

    public function testClickingAShiftOpensTheDialogAndApplyingSignsTheVolunteerUp(): void
    {
        $shift = $this->seed();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'applicant@example.com']);

        $this->browse();
        $this->signIn($user, self::PASSWORD);
        $this->client->request('GET', '/manage-shifts/apply?scope=all');
        $this->client->waitFor('.apply-block', 10);

        $this->client->executeScript('document.querySelector(".apply-block").click();');
        $this->client->waitFor('#apply-shift-modal.show .modal-footer button', 10);

        self::assertStringContainsString(
            'Gate',
            $this->client->executeScript('return document.querySelector("#apply-shift-modal").textContent;'),
            'the dialog describes the shift that was clicked',
        );

        $this->client->executeScript('document.querySelector("#apply-shift-modal form button:not([type=button])").click();');
        $this->client->waitFor('.apply-status-signed_up', 10);

        $this->em->clear();
        self::assertSame(
            1,
            $this->em->getRepository(ShiftEntry::class)->count(['shift' => $shift->getId()]),
            'applying from the dialog signs the volunteer up',
        );
        $this->assertNoConsoleErrors('the staff apply grid');
    }

    /**
     * A half-hour shift is the shortest the grid ever draws. The block is a flex column whose time
     * row never shrinks, so a row height too small for two lines leaves the title a sliver of
     * clipped text - the one thing that says which shift this is. Measured from the rendered boxes,
     * because the markup is right either way.
     */
    public function testAHalfHourBlockIsTallEnoughToShowItsTitle(): void
    {
        $seeded = $this->seed();
        $short = (new Shift())->setTitle('Quick sweep')
            ->setStartsAt(new \DateTimeImmutable('+1 day 14:00'))
            ->setEndsAt(new \DateTimeImmutable('+1 day 14:30'))
            ->setDepartment($seeded->getDepartment())
            ->setAudience(ShiftAudience::ALL_STAFF)
            ->setState(ShiftState::PUBLISHED);
        $this->em->persist($short);
        $this->em->flush();

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'applicant@example.com']);
        $this->browse();
        $this->signIn($user, self::PASSWORD);
        $this->client->request('GET', '/manage-shifts/apply?scope=all');
        $this->client->waitFor('.apply-block', 10);

        $blocks = $this->client->executeScript(
            'return Array.from(document.querySelectorAll(".apply-block")).map((b) => {'
            .'const t = b.querySelector(".apply-block-title");'
            .'return {title: t.textContent.trim(),'
            .' titleHeight: Math.round(t.getBoundingClientRect().height)};'
            .'});'
        );

        $half = array_values(array_filter($blocks, static fn (array $b): bool => $b['title'] === 'Quick sweep'));
        self::assertCount(1, $half, 'the half-hour shift names itself rather than showing its task or nothing');
        self::assertGreaterThanOrEqual(
            13,
            $half[0]['titleHeight'],
            'the block must leave the title a whole line; the flex row shrinks it to a sliver otherwise',
        );
        $this->assertNoConsoleErrors('the staff apply grid');
    }

    /** The axis and the department headers have to stay put while the grid scrolls under them. */
    public function testTheTimeAxisAndDepartmentHeaderStayPutWhileTheGridScrolls(): void
    {
        $this->seed();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'applicant@example.com']);

        $this->browse();
        $this->signIn($user, self::PASSWORD);
        $this->client->request('GET', '/manage-shifts/apply?scope=all');
        $this->client->waitFor('.apply-block', 10);

        $sticky = $this->client->executeScript(
            'return {axis: getComputedStyle(document.querySelector(".apply-axis")).position,'
            .' head: getComputedStyle(document.querySelector(".apply-department-head")).position,'
            .' scrolls: getComputedStyle(document.querySelector(".apply-grid")).overflow};'
        );

        self::assertSame('sticky', $sticky['axis']);
        self::assertSame('sticky', $sticky['head']);
        self::assertStringContainsString('auto', $sticky['scrolls']);
        $this->assertNoConsoleErrors('the staff apply grid layout');
    }
}
