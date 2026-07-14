<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Settings;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class OnboardingTest extends DatabaseWebTestCase
{
    private function makeUser(string $name, ?string $role): User
    {
        $group = new Group('G'.$name, 'g-'.$name, $role);
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->setSettings(new Settings($user));
        $user->addGroup($group);
        if ($role === 'ROLE_ADMIN') {
            $user->setTotpSecret('JBSWY3DPEHPK3PXP')->setTwoFactorEnabled(true);
        }
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testNonOnboardedUserIsRedirectedToWizard(): void
    {
        $this->client->loginUser($this->makeUser('vol', null));
        $this->client->request('GET', '/dashboard');
        self::assertResponseRedirects('/onboarding');
    }

    public function testAdminIsExemptFromOnboarding(): void
    {
        $this->client->loginUser($this->makeUser('boss', 'ROLE_ADMIN'));
        $this->client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();
    }

    public function testWalkingTheWizardCompletesOnboarding(): void
    {
        $volunteerType = new VolunteerType('Volunteer');
        $volunteerGroup = new Group('Volunteer', 'volunteer', null);
        $this->em->persist($volunteerType);
        $this->em->persist($volunteerGroup);
        $this->em->flush();

        $user = $this->makeUser('newbie', null);
        $this->client->loginUser($user);

        $this->client->request('POST', '/onboarding', ['consent' => '1']);
        self::assertResponseRedirects('/onboarding/profile');

        $this->client->request('POST', '/onboarding/profile', ['pronoun' => 'they/them', 'mobile' => '12345']);
        self::assertResponseRedirects('/onboarding/telegram');

        $this->client->request('POST', '/onboarding/telegram');
        self::assertResponseRedirects('/onboarding/notifications');

        $this->client->request('POST', '/onboarding/notifications', ['email_shifts' => '1', 'show_name' => '1']);
        self::assertResponseRedirects('/onboarding/finish');

        $this->client->request('POST', '/onboarding/finish', ['password' => 'newpassword1', 'password_confirm' => 'newpassword1']);
        self::assertResponseRedirects('/dashboard');

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        self::assertTrue($reloaded->isOnboardingCompleted());
        self::assertTrue($reloaded->getConsent()?->hasDataProcessing());
        self::assertTrue($reloaded->getConsent()?->isFullNameVisible());
        self::assertSame('they/them', $reloaded->getPersonalData()?->getPronoun());

        $membership = $this->em->getRepository(UserVolunteerType::class)
            ->findOneBy(['user' => $reloaded, 'volunteerType' => $volunteerType->getId()]);
        self::assertNotNull($membership, 'the Volunteer type is assigned automatically');
        self::assertTrue($membership->isConfirmed(), 'the automatic membership is confirmed, not pending');

        $groupSlugs = array_map(static fn (Group $g): string => $g->getSlug(), $reloaded->getGroups()->toArray());
        self::assertContains('volunteer', $groupSlugs, 'the Volunteer permission group is granted automatically');
    }

    public function testStaffGetTheStaffTypeButNotTheVolunteerGroup(): void
    {
        $staffType = new VolunteerType('Staff');
        $volunteerGroup = new Group('Volunteer', 'volunteer', null);
        $this->em->persist($staffType);
        $this->em->persist($volunteerGroup);
        $this->em->flush();

        $user = $this->makeUser('chief', 'ROLE_STAFF');
        $this->client->loginUser($user);

        $this->client->request('POST', '/onboarding', ['consent' => '1']);
        $this->client->request('POST', '/onboarding/profile');
        $this->client->request('POST', '/onboarding/telegram');
        $this->client->request('POST', '/onboarding/notifications');
        $this->client->request('POST', '/onboarding/finish', ['password' => 'newpassword1', 'password_confirm' => 'newpassword1']);
        self::assertResponseRedirects('/dashboard');

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());

        $membership = $this->em->getRepository(UserVolunteerType::class)
            ->findOneBy(['user' => $reloaded, 'volunteerType' => $staffType->getId()]);
        self::assertNotNull($membership);
        self::assertTrue($membership->isConfirmed());

        $groupSlugs = array_map(static fn (Group $g): string => $g->getSlug(), $reloaded->getGroups()->toArray());
        self::assertNotContains('volunteer', $groupSlugs);
    }
}
