<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Department scoping on the shift staffing screens.
 *
 * shift:manage is department-scoped, but PrivilegeVoter only enforces the scope
 * when a subject is supplied. A class-level #[IsGranted('shift:manage')] passes
 * none, so it grants unconditionally: every route below must re-check against
 * the shift it actually acts on.
 */
final class ShiftStaffingScopeTest extends DatabaseWebTestCase
{
    private ShiftScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = new ShiftScenario(
            $this->em,
            static::getContainer()->get(UserPasswordHasherInterface::class),
        );
    }

    /** A shift manager scoped to a department that owns nothing in this scenario. */
    private function foreignManager(): User
    {
        $other = new Department('Other', 'other-'.bin2hex(random_bytes(3)));
        $this->em->persist($other);

        $suffix = bin2hex(random_bytes(4));
        $group = new Group('Mgr '.$suffix, 'mgr-'.$suffix, 'ROLE_STAFF');
        foreach (['shift:manage', 'assignment:manage'] as $name) {
            $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => $name]) ?? new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $user = new User();
        $user->setName('mgr-'.$suffix)->setEmail('mgr-'.$suffix.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'secret123'));
        $user->completeOnboarding();
        $this->em->persist($user->assignGroup($group, $other));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** The scope check must not lock out the manager who legitimately owns the shift. */
    public function testManagerCanOpenStaffingForOwnDepartmentsShift(): void
    {
        $shift = $this->scenario->shift('Morning Gate');

        $suffix = bin2hex(random_bytes(4));
        $group = new Group('Own '.$suffix, 'own-'.$suffix, 'ROLE_STAFF');
        $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => 'shift:manage']) ?? new Privilege('shift:manage');
        $this->em->persist($privilege);
        $group->addPrivilege($privilege);
        $this->em->persist($group);

        $user = new User();
        $user->setName('own-'.$suffix)->setEmail('own-'.$suffix.'@example.com')->setPassword('x')
            ->setApiKey(bin2hex(random_bytes(16)));
        $user->completeOnboarding();
        $this->em->persist($user->assignGroup($group, $this->scenario->department));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/manage/shifts/'.$shift->getUuid().'/staffing');

        self::assertResponseIsSuccessful();
    }

    public function testManagerCannotOpenStaffingForAnotherDepartmentsShift(): void
    {
        $shift = $this->scenario->shift('Morning Gate');
        $this->client->loginUser($this->foreignManager());

        $this->client->request('GET', '/manage/shifts/'.$shift->getUuid().'/staffing');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testManagerCannotEditAnotherDepartmentsShift(): void
    {
        $shift = $this->scenario->shift('Morning Gate');
        $this->client->loginUser($this->foreignManager());

        $this->client->request('GET', '/manage/shifts/'.$shift->getUuid().'/edit');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The whole chain: reaching another department's staffing page also hands
     * over its CSRF tokens, so the no-show button - which can trigger the
     * automatic ban - is reachable for a volunteer the manager has no claim to.
     */
    public function testManagerCannotNoShowOnAnotherDepartmentsShift(): void
    {
        $shift = $this->scenario->shift('Morning Gate');
        $volunteer = $this->scenario->user([], $this->scenario->type);
        $entry = $this->scenario->signUp($volunteer, $shift);

        $this->client->loginUser($this->foreignManager());

        // Take the token from the page itself, exactly as an attacker would.
        $crawler = $this->client->request('GET', '/manage/shifts/'.$shift->getUuid().'/staffing');
        if ($this->client->getResponse()->getStatusCode() !== 200) {
            self::assertSame(403, $this->client->getResponse()->getStatusCode());

            return;
        }

        $token = $crawler->filter('form[action*="noshow"] input[name="_token"]')->first();
        self::assertGreaterThan(0, $token->count(), 'Expected the no-show form to be reachable to prove the exploit');

        $this->client->request('POST', '/manage/shifts/entries/'.$entry->getUuid().'/noshow', [
            '_token' => $token->attr('value'),
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }
}
