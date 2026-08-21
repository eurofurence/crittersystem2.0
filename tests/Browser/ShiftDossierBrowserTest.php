<?php

namespace App\Tests\Browser;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Opening the shift dossier from the info desk, in a real browser.
 *
 * The rest of the suite renders the fragment and reads it as HTML, which cannot tell a working
 * dialog from a trigger whose controller throws before it connects: the link would simply navigate
 * to the shift page and every assertion about the markup would still hold. Only a click shows it.
 */
final class ShiftDossierBrowserTest extends BrowserTestCase
{
    private const PASSWORD = 'secret123';

    /**
     * The desk group carries news:view because signing in lands on /news, where a 403 is a severe
     * console error reported against this test.
     */
    public function testTheDeskOpensTheDossierWithoutLeavingTheVolunteer(): void
    {
        $scenario = new ShiftScenario($this->em, static::getContainer()->get(UserPasswordHasherInterface::class));

        $shift = $scenario->shift('Morning Gate');
        $planner = $scenario->user(['shift:view']);
        $shift->setCreatedBy($planner);

        $volunteer = $scenario->user(['shift:view'], $scenario->type);
        $scenario->signUp($volunteer, $shift);

        $group = new Group('Desk', 'desk-'.bin2hex(random_bytes(2)), 'ROLE_STAFF');
        foreach (['user:locate', 'shift:view', 'shift:assign', 'news:view'] as $name) {
            $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => $name]) ?? new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $desk = new User();
        $desk->setName('desk')->setEmail('desk@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $desk->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($desk, self::PASSWORD));
        $desk->addGroup($group);
        $desk->completeOnboarding();
        $this->em->persist($desk);
        $this->em->flush();

        $this->browse();
        $this->signIn($desk, self::PASSWORD);
        $this->client->request('GET', '/backstage/distribute/'.$volunteer->getUuid());
        $this->client->waitFor('[data-controller="shift-dossier"]', 10);

        $this->client->getCrawler()->filter('[data-controller="shift-dossier"]')->first()->click();
        $this->client->waitForVisibility('.modal-body', 10);

        $body = $this->client->getCrawler()->filter('.modal-body')->text();
        self::assertStringContainsString('Morning Gate', $this->client->getCrawler()->filter('.modal-title')->text());
        self::assertStringContainsString($planner->getName(), $body, 'the desk holds shift:assign event-wide, so it sees who planned the shift');
        self::assertStringContainsString($volunteer->getName(), $body);
        self::assertStringContainsString('No description was given', $body);

        // The dialog must not have navigated: the operator is still on the volunteer they were helping.
        self::assertStringContainsString('/backstage/distribute/', $this->client->getCurrentURL());

        $this->assertNoConsoleErrors('the shift dossier dialog');
    }
}
