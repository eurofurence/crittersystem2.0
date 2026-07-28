<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\ShiftTask;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The planner side panel must show back the required volunteer-type counts a shift already carries
 * (from the wizard or an earlier edit). Regression: the counts were saved but rendered as 0 because
 * the Twig merge filter renumbered the integer volunteer-type-id keys, so the lookup always missed.
 */
final class PlannerPanelNeededTest extends DatabaseWebTestCase
{
    public function testPanelDisplaysSavedNeededCount(): void
    {
        $group = new Group('Managers', 'mgr', 'ROLE_STAFF');
        foreach (['manageshifts:view', 'shift:manage'] as $p) {
            $priv = new Privilege($p);
            $this->em->persist($priv);
            $group->addPrivilege($priv);
        }
        $this->em->persist($group);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('mgr')->setEmail('mgr@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $dept = new Department('Logistics', 'logistics');
        $this->em->persist($dept);
        $vt = new VolunteerType('Runner');
        $this->em->persist($vt);
        $task = new ShiftTask('Briefing');
        $task->setDepartment($dept);
        $this->em->persist($task);
        $this->em->flush();
        $vtId = $vt->getId();
        $this->client->loginUser($user);

        // Create a shift and set the requirement through the wizard.
        $crawler = $this->client->request('GET', '/manage-shifts/wizard?department='.$dept->getUuid());
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/manage-shifts/wizard', [
            '_token' => $token,
            'department' => $dept->getUuid(),
            'custom_dates' => '2026-06-01',
            'start_time' => '10:00',
            'end_time' => '12:00',
            'slot_minutes' => 120,
            'audience' => 'department_staff',
            'task' => $task->getId(),
            'needed' => [(string) $vtId => '3'],
        ]);

        $shift = $this->em->getRepository(Shift::class)->findAll()[0];
        self::assertSame(3, $shift->getNeededVolunteerTypes()->first()->getCount(), 'sanity: the wizard saved the count');

        // The panel that opens when the shift is reopened must show 3, not 0.
        $panel = $this->client->request('GET', '/manage-shifts/planner/shift/'.$shift->getUuid().'/panel');
        $value = $panel->filter('input[name="needed['.$vtId.']"]')->attr('value');
        self::assertSame('3', $value, 'the panel must display the saved required count');
    }
}
