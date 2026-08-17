<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\PersonalData;
use App\Entity\Privilege;
use App\Entity\User;
use App\Service\Security\LocationCheckInService;
use App\Tests\DatabaseWebTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The security desk screen: finding somebody, admitting them, taking it back, and the daily report.
 *
 * Overriding the entry rules is a separate privilege from performing a check-in, so an operator who
 * can work the door cannot also wave through somebody the rules refuse.
 */
final class SecurityCheckInPageTest extends DatabaseWebTestCase
{
    private ShiftScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = new ShiftScenario($this->em, static::getContainer()->get(UserPasswordHasherInterface::class));
    }

    /** @param list<string> $privileges */
    private function operator(array $privileges = ['security:checkin']): User
    {
        $group = new Group('Security '.bin2hex(random_bytes(2)), 'sec-'.bin2hex(random_bytes(3)), 'ROLE_STAFF');
        foreach ($privileges as $name) {
            $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => $name]) ?? new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $user = new User();
        $user->setName('op-'.bin2hex(random_bytes(3)))->setEmail('op-'.bin2hex(random_bytes(3)).'@example.com');
        $user->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        return $user;
    }

    private function critter(?int $badgeNumber, bool $withShift): User
    {
        $user = new User();
        $user->setName('critter-'.bin2hex(random_bytes(3)))->setEmail('critter-'.bin2hex(random_bytes(3)).'@example.com');
        $user->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $user->completeOnboarding();
        if ($badgeNumber !== null) {
            $personal = new PersonalData($user);
            $personal->setBadgeNumber($badgeNumber);
            $user->setPersonalData($personal);
            $this->em->persist($personal);
        }
        $this->em->persist($user);
        $this->em->flush();

        if ($withShift) {
            $shift = $this->scenario->shift('Door duty', 'today 00:00', '+1 hour', 2);
            $shift->setStartsAt(new \DateTimeImmutable('+30 minutes'))->setEndsAt(new \DateTimeImmutable('+4 hours'));
            $this->scenario->signUp($user, $shift);
            $this->em->flush();
        }

        return $user;
    }

    /** Read from the rendered page: a token minted outside a request has no session to live in. */
    private function token(User $subject): string
    {
        $crawler = $this->client->request('GET', '/backstage/security/'.$subject->getUuid());

        return (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
    }

    public function testTheDeskRefusesSomebodyWithoutThePrivilege(): void
    {
        $this->operator(['backstage:view']);

        $this->client->request('GET', '/backstage/security');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnEligibleCritterCanBeCheckedIn(): void
    {
        $this->operator();
        $subject = $this->critter(4242, true);

        $this->client->request('POST', '/backstage/security/'.$subject->getUuid().'/enter', [
            '_token' => $this->token($subject),
        ]);

        self::assertResponseRedirects();
        self::assertTrue(static::getContainer()->get(LocationCheckInService::class)->isInside(
            $this->em->getRepository(User::class)->find($subject->getId())
        ));
    }

    /** The screen names the rule that stopped them rather than only disabling the button. */
    public function testARefusedCritterSeesWhyAndNoCheckInButton(): void
    {
        $this->operator();
        $subject = $this->critter(null, false);

        $crawler = $this->client->request('GET', '/backstage/security/'.$subject->getUuid());

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('.alert-warning')->count());
        self::assertSame(
            0,
            $crawler->filter('form[action$="/enter"]')->count(),
            'without the override privilege there is nothing to submit'
        );
    }

    /**
     * The subject here is eligible, so the refusal can only come from the privilege check. A
     * refused subject would prove the same thing less precisely, and would render no form to take
     * a token from.
     */
    public function testWithoutTheOverridePrivilegeAReasonIsRejected(): void
    {
        $this->operator();
        $subject = $this->critter(4242, true);

        $this->client->request('POST', '/backstage/security/'.$subject->getUuid().'/enter', [
            '_token' => $this->token($subject),
            'override_reason' => 'let them in',
        ]);

        self::assertResponseRedirects();
        self::assertFalse(static::getContainer()->get(LocationCheckInService::class)->isInside(
            $this->em->getRepository(User::class)->find($subject->getId())
        ));
    }

    public function testWithTheOverridePrivilegeTheReasonIsRecorded(): void
    {
        $this->operator(['security:checkin', 'security:checkin:override']);
        $subject = $this->critter(null, false);

        $this->client->request('POST', '/backstage/security/'.$subject->getUuid().'/enter', [
            '_token' => $this->token($subject),
            'override_reason' => 'Known to the team',
        ]);

        self::assertResponseRedirects();
        $crawler = $this->client->request('GET', '/backstage/security/'.$subject->getUuid());
        self::assertStringContainsString('Known to the team', $crawler->filter('body')->text());
    }

    public function testWithdrawingTakesThemBackOutside(): void
    {
        $this->operator();
        $subject = $this->critter(4242, true);
        $service = static::getContainer()->get(LocationCheckInService::class);
        $service->enter($this->em->getRepository(User::class)->find($subject->getId()), null);

        $this->client->request('POST', '/backstage/security/'.$subject->getUuid().'/withdraw', [
            '_token' => $this->token($subject),
        ]);

        self::assertResponseRedirects();
        self::assertFalse($service->isInside($this->em->getRepository(User::class)->find($subject->getId())));
    }

    public function testTheReportCountsStaffAndCrittersSeparately(): void
    {
        $operator = $this->operator();
        $critter = $this->critter(4242, true);
        $service = static::getContainer()->get(LocationCheckInService::class);
        $service->enter($this->em->getRepository(User::class)->find($critter->getId()), null);
        $service->enter($this->em->getRepository(User::class)->find($operator->getId()), null);

        $crawler = $this->client->request('GET', '/backstage/security/report/day');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($critter->getName(), $crawler->filter('tbody')->text());
        self::assertStringContainsString($operator->getName(), $crawler->filter('tbody')->text());
    }

    public function testTheCsvNamesWhoCameIn(): void
    {
        $this->operator();
        $critter = $this->critter(4242, true);
        static::getContainer()->get(LocationCheckInService::class)
            ->enter($this->em->getRepository(User::class)->find($critter->getId()), null);

        $this->client->request('GET', '/backstage/security/report/day.csv');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('username,registration_number', $csv);
        self::assertStringContainsString($critter->getName(), $csv);
        self::assertStringContainsString('4242', $csv);
    }
}
