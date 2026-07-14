<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\User;
use App\Enum\ShiftState;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Shift Wizard end-to-end: the form renders and generates drafts. */
final class ShiftWizardPageTest extends DatabaseWebTestCase
{
    private function login(): Department
    {
        $group = new Group('Managers', 'mgr-'.bin2hex(random_bytes(2)), 'ROLE_STAFF');
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
        $this->em->flush();

        $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['email' => 'mgr@example.com']));

        return $dept;
    }

    public function testWizardGeneratesDraftShifts(): void
    {
        $dept = $this->login();

        $crawler = $this->client->request('GET', '/manage-shifts/wizard?department='.$dept->getUuid());
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/manage-shifts/wizard', [
            '_token' => $token,
            'department' => $dept->getUuid(),
            'custom_dates' => '2026-06-01, 2026-06-02',
            'start_time' => '10:00',
            'end_time' => '14:00',
            'slot_minutes' => 120,
            'audience' => 'department_staff',
        ]);

        self::assertResponseRedirects();
        $shifts = $this->em->getRepository(Shift::class)->findAll();
        self::assertCount(4, $shifts, '2 slots × 2 days');
        self::assertSame(ShiftState::DRAFT, $shifts[0]->getState());
    }
}
