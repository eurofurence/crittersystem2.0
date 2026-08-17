<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Repository\UserVolunteerTypeRepository;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The screen that finds and repairs users who finished onboarding without a Critter type.
 *
 * Onboarding matches the base type on its role, so a system where no type carries "volunteer"
 * hands every non-staff user nothing while telling them onboarding worked. They then fail shift
 * eligibility, which asks for a confirmed membership, and nothing on screen explains why.
 *
 * The screen has to report the unclaimed role, not only the affected users: repairing users while
 * the role is unclaimed assigns nobody anything and the next signup breaks identically.
 */
final class VolunteerTypeRepairTest extends DatabaseWebTestCase
{
    private function admin(): User
    {
        $group = new Group('VT managers', 'vtmgr-'.bin2hex(random_bytes(3)), 'ROLE_STAFF');
        $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => 'volunteertype:manage']) ?? new Privilege('volunteertype:manage');
        $this->em->persist($privilege);
        $group->addPrivilege($privilege);
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('vtadmin-'.bin2hex(random_bytes(3)))->setEmail('vtadmin-'.bin2hex(random_bytes(3)).'@example.com');
        $user->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        return $user;
    }

    /** An onboarded user holding no volunteer type: the state this screen exists to find. */
    private function strandedUser(string $name): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword('x');
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function type(string $name, ?string $role): VolunteerType
    {
        $type = new VolunteerType($name);
        $type->setRole($role);
        $this->em->persist($type);
        $this->em->flush();

        return $type;
    }

    /**
     * The token as the page actually rendered it.
     *
     * Minting one from the container needs a session that only a request creates, and reading the
     * rendered value also proves the form carries one at all.
     */
    private function tokenFor(string $action): string
    {
        $crawler = $this->client->request('GET', '/manage/volunteer-types/repair');

        return (string) $crawler->filter(sprintf('form[action="%s"] input[name="_token"]', $action))->first()->attr('value');
    }

    public function testTheStrandedUserIsListed(): void
    {
        $this->admin();
        $this->type('Critter', VolunteerType::ROLE_VOLUNTEER);
        $stranded = $this->strandedUser('stranded-one');

        $crawler = $this->client->request('GET', '/manage/volunteer-types/repair');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($stranded->getName(), $crawler->filter('tbody')->text());
    }

    /** Somebody who already holds a type is not a repair candidate and must not be offered. */
    public function testAUserWithATypeIsNotListed(): void
    {
        $this->admin();
        $type = $this->type('Critter', VolunteerType::ROLE_VOLUNTEER);
        $held = $this->strandedUser('already-held');
        $this->em->persist(new UserVolunteerType($held, $type));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/manage/volunteer-types/repair');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($held->getName(), $crawler->filter('body')->text());
    }

    /**
     * The cause is reported, not just the symptom. With no type carrying the role the screen must
     * say so, because repairing users in that state assigns nothing.
     */
    public function testAnUnclaimedRoleIsReported(): void
    {
        $this->admin();
        $this->type('Staff', VolunteerType::ROLE_STAFF);
        $this->type('Critter', null);

        $crawler = $this->client->request('GET', '/manage/volunteer-types/repair');

        self::assertResponseIsSuccessful();
        self::assertSame(
            1,
            $crawler->filter('form[action="/manage/volunteer-types/repair/role"]')->count(),
            'staff is claimed, so exactly the volunteer role is offered'
        );
    }

    public function testClaimingTheRoleMakesTheTypeTheDefault(): void
    {
        $this->admin();
        $type = $this->type('Critter', null);

        $this->client->request('POST', '/manage/volunteer-types/repair/role', [
            '_token' => $this->tokenFor('/manage/volunteer-types/repair/role'),
            'role' => VolunteerType::ROLE_VOLUNTEER,
            'type' => (string) $type->getUuid(),
        ]);

        self::assertResponseRedirects('/manage/volunteer-types/repair');
        $this->em->clear();
        self::assertSame(
            VolunteerType::ROLE_VOLUNTEER,
            $this->em->getRepository(VolunteerType::class)->find($type->getId())?->getRole()
        );
    }

    /** Two types holding one role would make the onboarding lookup pick one at random. */
    public function testClaimingARoleTakesItFromTheTypeThatHeldIt(): void
    {
        $this->admin();
        $old = $this->type('Old base', VolunteerType::ROLE_VOLUNTEER);
        $new = $this->type('Critter', null);

        $this->client->request('POST', '/manage/volunteer-types/repair/role', [
            '_token' => $this->tokenFor('/manage/volunteer-types/repair/role'),
            'role' => VolunteerType::ROLE_VOLUNTEER,
            'type' => (string) $new->getUuid(),
        ]);

        $this->em->clear();
        $repo = $this->em->getRepository(VolunteerType::class);
        self::assertNull($repo->find($old->getId())?->getRole(), 'the previous holder gives the role up');
        self::assertSame(VolunteerType::ROLE_VOLUNTEER, $repo->find($new->getId())?->getRole());
    }

    public function testRepairingOneUserGivesThemAConfirmedMembership(): void
    {
        $this->admin();
        $this->type('Critter', VolunteerType::ROLE_VOLUNTEER);
        $stranded = $this->strandedUser('fix-me');

        $this->client->request('POST', '/manage/volunteer-types/repair/apply', [
            '_token' => $this->tokenFor('/manage/volunteer-types/repair/apply'),
            'scope' => 'selected',
            'users' => [(string) $stranded->getUuid()],
        ]);

        self::assertResponseRedirects();
        $memberships = static::getContainer()->get(UserVolunteerTypeRepository::class)->findByUser(
            $this->em->getRepository(User::class)->find($stranded->getId())
        );
        self::assertCount(1, $memberships);
        self::assertTrue($memberships[0]->isConfirmed(), 'an unconfirmed membership still cannot take a shift');
    }

    public function testRepairingAllClearsTheBacklog(): void
    {
        $this->admin();
        $this->type('Critter', VolunteerType::ROLE_VOLUNTEER);
        $this->strandedUser('bulk-one');
        $this->strandedUser('bulk-two');

        $this->client->request('POST', '/manage/volunteer-types/repair/apply', [
            '_token' => $this->tokenFor('/manage/volunteer-types/repair/apply'),
            'scope' => 'all',
        ]);

        self::assertResponseRedirects();
        $crawler = $this->client->request('GET', '/manage/volunteer-types/repair');
        self::assertStringNotContainsString('bulk-one', $crawler->filter('body')->text());
        self::assertStringNotContainsString('bulk-two', $crawler->filter('body')->text());
    }

    /** Repairing while the role is unclaimed must change nothing rather than appear to succeed. */
    public function testRepairingWithNoDefaultTypeAssignsNothing(): void
    {
        $this->admin();
        $this->type('Critter', null);
        $stranded = $this->strandedUser('no-default');

        $this->client->request('POST', '/manage/volunteer-types/repair/apply', [
            '_token' => $this->tokenFor('/manage/volunteer-types/repair/apply'),
            'scope' => 'all',
        ]);

        self::assertResponseRedirects();
        self::assertCount(0, static::getContainer()->get(UserVolunteerTypeRepository::class)->findByUser(
            $this->em->getRepository(User::class)->find($stranded->getId())
        ));
    }

    public function testTheScreenRefusesSomebodyWithoutThePrivilege(): void
    {
        $user = new User();
        $user->setName('nobody-'.bin2hex(random_bytes(3)))->setEmail('nobody-'.bin2hex(random_bytes(3)).'@example.com');
        $user->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword('x');
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/manage/volunteer-types/repair');

        self::assertResponseStatusCodeSame(403);
    }
}
