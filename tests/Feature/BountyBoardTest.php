<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\HelpCall;
use App\Entity\NeededVolunteerType;
use App\Entity\OperationalStatusOverride;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Service\OperationalStatusService;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Bounty Board eligibility and transaction-safe acceptance. */
final class BountyBoardTest extends DatabaseWebTestCase
{
    public function testEligibleUserSeesAndAcceptsCall(): void
    {
        $group = new Group('Vols', 'volunteer', null);
        $priv = new Privilege('call:respond');
        $this->em->persist($priv);
        $group->addPrivilege($priv);
        $this->em->persist($group);
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('Val')->setEmail('val@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $m = new UserVolunteerType($user, $type);
        $m->setConfirmedBy($user);
        $this->em->persist($m);
        $this->em->persist(new OperationalStatusOverride($user, OperationalStatusService::FREE_TO_HELP, new \DateTimeImmutable('+3 hours')));

        $dept = new Department('Ops', 'ops');
        $this->em->persist($dept);
        $shift = (new Shift())->setTitle('Gate')
            ->setStartsAt(new \DateTimeImmutable('+1 hour'))->setEndsAt(new \DateTimeImmutable('+3 hours'))
            ->setDepartment($dept);
        $this->em->persist($shift);
        $need = new NeededVolunteerType($type, 1);
        $shift->addNeededVolunteerType($need);
        $this->em->persist($need);
        $call = new HelpCall($shift, null, 1);
        $this->em->persist($call);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/bounty');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Gate');

        $token = $crawler->filter('form[action$="/accept"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/calls/'.$call->getUuid().'/accept', ['_token' => $token]);
        self::assertResponseRedirects('/bounty');

        self::assertNotNull($this->em->getRepository(ShiftEntry::class)->findOneBy(['shift' => $shift, 'user' => $user]));
    }
}
