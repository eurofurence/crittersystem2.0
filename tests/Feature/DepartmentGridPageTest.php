<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Department shift grid page + side panel. */
final class DepartmentGridPageTest extends DatabaseWebTestCase
{
    private function login(): Shift
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
        $shift = (new Shift())->setTitle('Gate')
            ->setStartsAt(new \DateTimeImmutable('+1 day 10:00'))
            ->setEndsAt(new \DateTimeImmutable('+1 day 12:00'))
            ->setDepartment($dept);
        $this->em->persist($shift);
        $this->em->flush();

        $this->client->loginUser($user);

        return $shift;
    }

    public function testGridRenders(): void
    {
        $shift = $this->login();
        $crawler = $this->client->request('GET', '/manage-shifts/grid');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Gate');
        self::assertSame(1, $crawler->filter('turbo-frame#grid-panel')->count());
    }

    public function testSidePanelLoads(): void
    {
        $shift = $this->login();
        $this->client->request('GET', '/manage-shifts/grid/shift/'.$shift->getUuid().'/panel');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#grid-panel');
        self::assertSelectorTextContains('body', 'Assignments');
    }
}
