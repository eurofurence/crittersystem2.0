<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\NamedPosition;
use App\Entity\PositionGroup;
use App\Entity\Privilege;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Advanced Matrix Planner renders configured position columns. */
final class MatrixPageTest extends DatabaseWebTestCase
{
    public function testMatrixShowsDepartmentConfiguredColumns(): void
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

        $dept = new Department('Stage', 'stage');
        $this->em->persist($dept);
        $pg = new PositionGroup($dept, 'Light');
        $this->em->persist($pg);
        $pos = new NamedPosition($pg, 'FOH');
        $this->em->persist($pos);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/manage-shifts/matrix?department='.$dept->getUuid());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.matrix-group', 'Light');
        self::assertSelectorTextContains('.matrix-position', 'FOH');
    }

    public function testOrdinaryStaffCannotOpenMatrix(): void
    {
        $group = new Group('Staff', 'staff-'.bin2hex(random_bytes(2)), 'ROLE_STAFF');
        $priv = new Privilege('manageshifts:view');
        $this->em->persist($priv);
        $group->addPrivilege($priv);
        $this->em->persist($group);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('s')->setEmail('s@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/manage-shifts/matrix');
        self::assertResponseStatusCodeSame(403);
    }
}
