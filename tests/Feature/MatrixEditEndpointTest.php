<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\NamedPosition;
use App\Entity\PositionGroup;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\ShiftPosition;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Matrix structure-editing endpoints: create group and copy. */
final class MatrixEditEndpointTest extends DatabaseWebTestCase
{
    private function login(): Department
    {
        $group = new Group('Managers', 'mgr-'.bin2hex(random_bytes(2)), 'ROLE_STAFF');
        foreach (['manageshifts:view', 'shift:manage', 'shift:assign'] as $p) {
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
        $this->em->flush();

        $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['email' => 'mgr@example.com']));

        return $dept;
    }

    private function token(): string
    {
        $crawler = $this->client->request('GET', '/manage-shifts/matrix');

        return $crawler->filter('input[name="_token"]')->attr('value');
    }

    public function testCreateGroupEndpoint(): void
    {
        $dept = $this->login();
        $token = $this->token();

        $this->client->request('POST', '/manage-shifts/matrix/group', [
            '_token' => $token,
            'department' => $dept->getUuid(),
            'name' => 'Light',
        ]);

        self::assertResponseIsSuccessful();
        self::assertNotNull($this->em->getRepository(PositionGroup::class)->findOneBy(['name' => 'Light']));
    }

    public function testUserSearchSurfacesUsersOutsideTheDepartment(): void
    {
        // The cell editor must be able to place any volunteer, not only department members - the
        // whole point of the search box that replaced the members-only dropdown.
        $dept = $this->login();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $outsider = new User();
        $outsider->setName('Zoe Outsider')->setEmail('zoe@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $outsider->setPassword($hasher->hashPassword($outsider, 'secret123'));
        $outsider->completeOnboarding();
        $this->em->persist($outsider);
        $this->em->flush();

        $this->client->request('GET', '/manage-shifts/matrix/users', [
            'department' => (string) $dept->getUuid(),
            'q' => 'Zoe',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertContains('Zoe Outsider', array_column($data['results'], 'name'));
    }

    public function testCopyStructureEndpoint(): void
    {
        $dept = $this->login();
        $group = new PositionGroup($dept, 'Stage');
        $this->em->persist($group);
        $pos = new NamedPosition($group, 'FOH');
        $this->em->persist($pos);

        $mk = function (string $t) use ($dept): Shift {
            $s = (new Shift())->setTitle($t)
                ->setStartsAt(new \DateTimeImmutable('+1 day 20:00'))
                ->setEndsAt(new \DateTimeImmutable('+1 day 23:00'))
                ->setDepartment($dept);
            $this->em->persist($s);

            return $s;
        };
        $from = $mk('Source');
        $to = $mk('Target');
        $sp = new ShiftPosition($from, $pos);
        $from->addShiftPosition($sp);
        $this->em->persist($sp);
        $this->em->flush();

        $this->client->request('POST', '/manage-shifts/matrix/copy', [
            '_token' => $this->token(),
            'from' => $from->getId(),
            'to' => [$to->getId()],
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $reloaded = $this->em->getRepository(Shift::class)->find($to->getId());
        self::assertCount(1, $reloaded->getShiftPositions());
    }
}
