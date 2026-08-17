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

/** Staff shift application apply/cancel and last-slot concurrency. */
final class StaffApplyFlowTest extends DatabaseWebTestCase
{
    private function staffGroup(): Group
    {
        $g = new Group('Staff', 'staff', 'ROLE_STAFF');
        $priv = new Privilege('manageshifts:view');
        $this->em->persist($priv);
        $g->addPrivilege($priv);
        $priv2 = new Privilege('shift:self');
        $this->em->persist($priv2);
        $g->addPrivilege($priv2);
        $this->em->persist($g);

        return $g;
    }

    private function member(Group $group, VolunteerType $type, string $name): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $m = new UserVolunteerType($user, $type);
        $m->setConfirmedBy($user);
        $this->em->persist($m);

        return $user;
    }

    /**
     * Applying and cancelling both go through the rendered form, so the CSRF token is the one the
     * page minted into the session.
     */
    public function testApplyThenCancel(): void
    {
        $group = $this->staffGroup();
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $dept = new Department('Ops', 'ops');
        $this->em->persist($dept);
        $shift = (new Shift())->setTitle('Gate')
            ->setStartsAt(new \DateTimeImmutable('+2 day 10:00'))
            ->setEndsAt(new \DateTimeImmutable('+2 day 12:00'))
            ->setDepartment($dept)->setAudience(ShiftAudience::ALL_STAFF)->setState(ShiftState::PUBLISHED);
        $this->em->persist($shift);
        $need = new NeededVolunteerType($type, 2);
        $shift->addNeededVolunteerType($need);
        $this->em->persist($need);
        $user = $this->member($group, $type, 'staff');
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('POST', '/manage-shifts/apply/'.$shift->getUuid(), [
            '_token' => $this->formToken($shift, 'apply'),
        ]);
        self::assertResponseRedirects('/manage-shifts/apply');
        self::assertNotNull($this->em->getRepository(ShiftEntry::class)->findOneBy(['shift' => $shift, 'user' => $user]));

        $this->client->request('POST', '/manage-shifts/apply/'.$shift->getUuid().'/cancel', [
            '_token' => $this->formToken($shift, 'cancel'),
        ]);
        self::assertResponseRedirects('/manage-shifts/apply');
        $this->em->clear();
        self::assertNull($this->em->getRepository(ShiftEntry::class)->findOneBy(['shift' => $shift->getId(), 'user' => $user->getId()]));
    }

    /**
     * The last free slot goes to one applicant only. Both volunteers load the page while the slot is
     * still open, so the second applies from a view that is already stale and the server, not the
     * markup, is what refuses them.
     */
    public function testLastSlotAllowsOnlyOneApplicant(): void
    {
        $group = $this->staffGroup();
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $dept = new Department('Ops', 'ops');
        $this->em->persist($dept);
        $shift = (new Shift())->setTitle('Solo')
            ->setStartsAt(new \DateTimeImmutable('+2 day 10:00'))
            ->setEndsAt(new \DateTimeImmutable('+2 day 12:00'))
            ->setDepartment($dept)->setAudience(ShiftAudience::ALL_STAFF)->setState(ShiftState::PUBLISHED);
        $this->em->persist($shift);
        $need = new NeededVolunteerType($type, 1);
        $shift->addNeededVolunteerType($need);
        $this->em->persist($need);
        $first = $this->member($group, $type, 'first');
        $second = $this->member($group, $type, 'second');
        $this->em->flush();

        $this->client->loginUser($first);
        $tokenFirst = $this->formToken($shift, 'apply');
        $this->client->loginUser($second);
        $tokenSecond = $this->formToken($shift, 'apply');

        $this->client->loginUser($first);
        $this->client->request('POST', '/manage-shifts/apply/'.$shift->getUuid(), ['_token' => $tokenFirst]);
        $this->client->loginUser($second);
        $this->client->request('POST', '/manage-shifts/apply/'.$shift->getUuid(), ['_token' => $tokenSecond]);

        self::assertSame(1, $this->em->getRepository(ShiftEntry::class)->count(['shift' => $shift->getId()]), 'only one applicant takes the single slot');
    }

    /** Read the apply/cancel CSRF token from the shift dialog, which is where the forms now live. */
    private function formToken(Shift $shift, string $action): string
    {
        $prefix = $action === 'apply'
            ? '/manage-shifts/apply/'.$shift->getUuid()
            : '/manage-shifts/apply/'.$shift->getUuid().'/cancel';
        $crawler = $this->client->request('GET', '/manage-shifts/apply/shift/'.$shift->getUuid());

        return $crawler->filter('form[action^="'.$prefix.'"] input[name="_token"]')->attr('value');
    }
}
