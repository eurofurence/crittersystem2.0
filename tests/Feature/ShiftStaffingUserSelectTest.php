<?php

namespace App\Tests\Feature;

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
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The staffing screen's tag-style user picker: the JSON username search that feeds it, and the
 * batch assign that adds every picked user at once.
 */
final class ShiftStaffingUserSelectTest extends DatabaseWebTestCase
{
    private Shift $shift;
    private VolunteerType $type;

    private function manager(): User
    {
        $group = new Group('Managers', 'mgr', 'ROLE_STAFF');
        foreach (['manageshifts:view', 'shift:manage', 'assignment:manage', 'assignment:override'] as $p) {
            $priv = new Privilege($p);
            $this->em->persist($priv);
            $group->addPrivilege($priv);
        }
        $this->em->persist($group);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $mgr = new User();
        $mgr->setName('boss')->setEmail('boss@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $mgr->setPassword($hasher->hashPassword($mgr, 'secret123'));
        $mgr->addGroup($group);
        $mgr->completeOnboarding();
        $this->em->persist($mgr);

        $dept = new Department('Logistics', 'logistics');
        $this->em->persist($dept);
        $this->type = new VolunteerType('Runner');
        $this->em->persist($this->type);

        $this->shift = (new Shift())
            ->setTitle('Night')
            ->setStartsAt(new \DateTimeImmutable('2026-06-01 10:00'))
            ->setEndsAt(new \DateTimeImmutable('2026-06-01 12:00'))
            ->setDepartment($dept)
            ->setAudience(ShiftAudience::PUBLIC_VOLUNTEER)
            ->setState(ShiftState::DRAFT);
        $need = new NeededVolunteerType($this->type, 3);
        $this->shift->addNeededVolunteerType($need);
        $this->em->persist($this->shift);
        $this->em->persist($need);
        $this->em->flush();

        return $mgr;
    }

    private function member(string $name, bool $confirmed = true, ?string $role = null): User
    {
        $u = new User();
        $u->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        if ($role !== null) {
            $g = new Group('G'.$name, 'g-'.$name, $role);
            $this->em->persist($g);
            $u->addGroup($g);
        }
        $this->em->persist($u);
        $m = new UserVolunteerType($u, $this->type);
        if ($confirmed) {
            $m->setConfirmedBy($u);
        }
        $this->em->persist($m);
        $this->em->flush();

        return $u;
    }

    public function testSearchMatchesUsernamePartiallyWithStaffFlag(): void
    {
        $this->client->loginUser($this->manager());
        $this->member('alice', role: 'ROLE_STAFF');
        $this->member('alistair');
        $this->member('bob');

        $this->client->request('GET', '/manage-shifts/shift/'.$this->shift->getUuid().'/staffing/search?q=ali');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $names = array_column($data['results'], 'name');
        sort($names);
        self::assertSame(['alice', 'alistair'], $names, 'partial username match, no bob');

        $alice = array_values(array_filter($data['results'], fn ($r) => $r['name'] === 'alice'))[0];
        self::assertTrue($alice['staff'], 'staff flag drives the (staff) suffix');
        self::assertNull($alice['avatar'], 'no avatar set -> null (client falls back to initials)');
    }

    public function testSearchExcludesAlreadyAssigned(): void
    {
        $this->client->loginUser($this->manager());
        $carol = $this->member('carol');
        $this->em->persist(new ShiftEntry($this->shift, $this->type, $carol));
        $this->em->flush();

        $this->client->request('GET', '/manage-shifts/shift/'.$this->shift->getUuid().'/staffing/search?q=carol');
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame([], $data['results'], 'an already-assigned user is not offered again');
    }

    public function testBatchAssignAddsEveryPickedMemberAndReportsNonMembers(): void
    {
        $this->client->loginUser($this->manager());
        $a = $this->member('anna');
        $b = $this->member('ben');
        $stranger = $this->member('stray', confirmed: false); // not a confirmed member of the needed type

        $crawler = $this->client->request('GET', '/manage-shifts/shift/'.$this->shift->getUuid().'/staffing');
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/manage-shifts/shift/'.$this->shift->getUuid().'/staffing/assign', [
            '_token' => $token,
            'users' => [(string) $a->getId(), (string) $b->getId(), (string) $stranger->getId()],
        ]);
        self::assertResponseRedirects();

        $this->em->clear();
        $shift = $this->em->getRepository(Shift::class)->findAll()[0];
        $assignedNames = array_map(fn (ShiftEntry $e) => $e->getUser()->getName(), $shift->getEntries()->toArray());
        sort($assignedNames);
        self::assertSame(['anna', 'ben'], $assignedNames, 'both confirmed members assigned, the non-member skipped');
    }

    public function testStaffingPageRendersThePicker(): void
    {
        $this->client->loginUser($this->manager());
        $crawler = $this->client->request('GET', '/manage-shifts/shift/'.$this->shift->getUuid().'/staffing');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('[data-controller="user-select"]')->count());
    }
}
