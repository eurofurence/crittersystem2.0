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

/** Advanced staffing matrix PDF export. */
final class MatrixPdfTest extends DatabaseWebTestCase
{
    private function login(): Department
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

        $dept = new Department('Stage', 'stage');
        $this->em->persist($dept);
        $pg = new PositionGroup($dept, 'Light');
        $this->em->persist($pg);
        $pos = new NamedPosition($pg, 'FOH');
        $this->em->persist($pos);
        $shift = (new Shift())->setTitle('Show')
            ->setStartsAt(new \DateTimeImmutable('+1 day 20:00'))
            ->setEndsAt(new \DateTimeImmutable('+1 day 23:00'))
            ->setDepartment($dept);
        $this->em->persist($shift);
        $sp = new ShiftPosition($shift, $pos);
        $shift->addShiftPosition($sp);
        $this->em->persist($sp);
        $this->em->flush();

        $this->client->loginUser($user);

        return $dept;
    }

    public function testMatrixPdfExportReturnsPdf(): void
    {
        $dept = $this->login();
        $this->client->request('GET', '/manage-shifts/matrix.pdf?department='.$dept->getUuid());

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', $this->client->getResponse()->getContent());
        self::assertGreaterThan(1000, \strlen($this->client->getResponse()->getContent()));
    }

    public function testInteractiveMatrixLinksToPdf(): void
    {
        $dept = $this->login();
        $crawler = $this->client->request('GET', '/manage-shifts/matrix?department='.$dept->getUuid());

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('a[href*="matrix.pdf"]')->count());
    }
}
