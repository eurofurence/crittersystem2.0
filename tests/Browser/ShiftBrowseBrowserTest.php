<?php

namespace App\Tests\Browser;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Location;
use App\Entity\NeededVolunteerType;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Enum\ShiftState;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The volunteer shift browser in a real browser.
 *
 * Every action on /shifts happens inside the dialog, and the card's only control is a link.
 * PHPUnit renders that link and never clicks it, so the whole apply path on this screen is
 * invisible to it: if the Stimulus action name, the footer links or the field copying broke, the
 * markup would still be correct and every other test would still pass.
 */
final class ShiftBrowseBrowserTest extends BrowserTestCase
{
    private const PASSWORD = 'secret123';

    /**
     * A published shift, and a volunteer confirmed for the role it needs.
     *
     * `news:view` is part of the group because signing in lands on /news, and a 403 there is a
     * severe console error that {@see assertNoConsoleErrors()} would report against this screen.
     */
    private function seed(): Shift
    {
        $group = new Group('Vols', 'vols-'.bin2hex(random_bytes(2)), 'ROLE_USER');
        foreach (['shift:view', 'shift:self', 'news:view'] as $name) {
            $privilege = new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('browser-vol')->setEmail('browser-vol@example.com')->setApiKey(bin2hex(random_bytes(16)));
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
        $location = (new Location('Main Hall'))->setAlias('main-hall');
        $this->em->persist($location);

        $shift = (new Shift())->setTitle('Gate Duty')
            ->setStartsAt(new \DateTimeImmutable('+1 day 10:00'))
            ->setEndsAt(new \DateTimeImmutable('+1 day 12:00'))
            ->setDepartment($department)
            ->setLocation($location)
            ->setState(ShiftState::PUBLISHED);
        $need = new NeededVolunteerType($type, 2);
        $shift->addNeededVolunteerType($need);
        $this->em->persist($shift);
        $this->em->persist($need);

        $this->em->flush();

        return $shift;
    }

    /** The role is picked inside the dialog: the card carries no dropdown of its own. */
    public function testTheViewControlOpensTheDialogAndApplyingSignsTheVolunteerUp(): void
    {
        $shift = $this->seed();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'browser-vol@example.com']);
        $date = $shift->getStartsAt()->format('Y-m-d');

        $this->browse();
        $this->signIn($user, self::PASSWORD);
        $this->client->request('GET', '/shifts?date='.$date);
        $this->client->waitFor('a[data-action="shift-group#trigger"]', 10);

        $this->client->executeScript('document.querySelector(\'a[data-action="shift-group#trigger"]\').click();');
        $this->client->waitFor('.modal.show [data-role="confirm"]', 10);

        $body = $this->client->executeScript('return document.querySelector(".modal.show").textContent;');
        self::assertStringContainsString('Gate Duty', $body, 'the dialog describes the shift that was opened');
        self::assertStringContainsString('Crew', $body, 'and the roles it asks for');

        $this->client->executeScript(
            'const s = document.querySelector(\'.modal.show select[name^="group_type"]\');'
            .'if (s) { s.value = s.options[1].value; s.dispatchEvent(new Event("change", {bubbles: true})); }'
        );
        self::assertFalse(
            $this->client->executeScript('return document.querySelector(\'.modal.show [data-role="confirm"]\').disabled;'),
            'picking the role in the dialog must enable the confirm button',
        );

        $this->client->executeScript('document.querySelector(\'.modal.show [data-role="confirm"]\').click();');
        $this->client->waitForElementToContain('body', 'Signed up', 10);

        $this->em->clear();
        self::assertSame(
            1,
            $this->em->getRepository(ShiftEntry::class)->count(['shift' => $shift->getId()]),
            'confirming the dialog signs the volunteer up',
        );
        $this->assertNoConsoleErrors('the shift browser');
    }

    public function testTheDialogOffersTheLinkToTheShiftPage(): void
    {
        $shift = $this->seed();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'browser-vol@example.com']);

        $this->browse();
        $this->signIn($user, self::PASSWORD);
        $this->client->request('GET', '/shifts?date='.$shift->getStartsAt()->format('Y-m-d'));
        $this->client->waitFor('a[data-action="shift-group#trigger"]', 10);

        $this->client->executeScript('document.querySelector(\'a[data-action="shift-group#trigger"]\').click();');
        $this->client->waitFor('.modal.show [data-role="links"] a', 10);

        self::assertSame(
            '/shifts/'.$shift->getUuid(),
            $this->client->executeScript(
                'return new URL(document.querySelector(\'.modal.show [data-role="links"] a\').href).pathname;'
            ),
        );
        $this->assertNoConsoleErrors('the shift dialog');
    }
}
