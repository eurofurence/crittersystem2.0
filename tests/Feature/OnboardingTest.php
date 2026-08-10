<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Settings;
use App\Entity\TelegramConfiguration;
use App\Entity\TelegramLinkRequest;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Tests\DatabaseWebTestCase;
use App\Telegram\TelegramLinkService;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class OnboardingTest extends DatabaseWebTestCase
{
    private function enableTelegram(?string $botUsername = null): void
    {
        $config = new TelegramConfiguration();
        $config->setEnabled(true)->setApiEndpoint('https://bot.example')->setBotUsername($botUsername);
        $this->em->persist($config);
        $this->em->flush();
    }

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

    /** Visibility provenance is stamped when the consent flags are set, which the timestamp proves. */
    public function testWalkingTheWizardCompletesOnboarding(): void
    {
        $volunteerType = (new VolunteerType('Volunteer'))->setRole(VolunteerType::ROLE_VOLUNTEER);
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

        $this->client->request('POST', '/onboarding/notifications', ['email_shifts' => '1', 'show_name' => '1', 'show_email' => '1']);
        self::assertResponseRedirects('/onboarding/theme');

        $this->client->request('POST', '/onboarding/theme', ['theme' => 'dark']);
        self::assertResponseRedirects('/onboarding/finish');

        $this->client->request('POST', '/onboarding/finish', ['password' => 'newpassword1', 'password_confirm' => 'newpassword1']);
        self::assertResponseRedirects('/news');

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        self::assertTrue($reloaded->isOnboardingCompleted());
        self::assertTrue($reloaded->getConsent()?->hasDataProcessing());
        self::assertTrue($reloaded->getConsent()?->isFullNameVisible());
        self::assertTrue($reloaded->getConsent()?->isEmailVisible());
        self::assertNotNull($reloaded->getConsent()?->getVisibilityConsentedAt());
        self::assertSame('they/them', $reloaded->getPersonalData()?->getPronoun());

        $membership = $this->em->getRepository(UserVolunteerType::class)
            ->findOneBy(['user' => $reloaded, 'volunteerType' => $volunteerType->getId()]);
        self::assertNotNull($membership, 'the Volunteer type is assigned automatically');
        self::assertTrue($membership->isConfirmed(), 'the automatic membership is confirmed, not pending');

        $groupSlugs = array_map(static fn (Group $g): string => $g->getSlug(), $reloaded->getGroups()->toArray());
        self::assertContains('volunteer', $groupSlugs, 'the Volunteer permission group is granted automatically');
    }

    /** Sharing nothing but a notification preference leaves the volunteer unreachable, so the step re-renders. */
    public function testNotificationsStepRequiresAtLeastOneSharedChannel(): void
    {
        $user = $this->makeUser('unreachable', null);
        $this->client->loginUser($user);
        $this->client->request('POST', '/onboarding', ['consent' => '1']);

        $this->client->request('POST', '/onboarding/notifications', ['email_shifts' => '1']);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        self::assertFalse($reloaded->isOnboardingCompleted());
    }

    /** With no TelegramConfiguration the feature is off and the step is dead, so onboarding jumps past it. */
    public function testTelegramStepAutoSkipsWhenFeatureDisabled(): void
    {
        $this->client->loginUser($this->makeUser('nolink', null));
        $this->client->request('GET', '/onboarding/telegram');
        self::assertResponseRedirects('/onboarding/notifications');
    }

    /** Visiting the step issues a code, and the one-tap button must point at that code. */
    public function testTelegramStepShowsDeepLinkButtonWhenEnabled(): void
    {
        $this->enableTelegram('MyEventBot');
        $user = $this->makeUser('linker', null);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/onboarding/telegram');
        self::assertResponseIsSuccessful();

        $pending = $this->em->getRepository(TelegramLinkRequest::class)->findOneBy(['user' => $user]);
        self::assertNotNull($pending);
        $href = $crawler->filter('a[href^="https://t.me/"]')->attr('href');
        self::assertSame('https://t.me/MyEventBot?start='.$pending->getCode(), $href);
    }

    /**
     * The confirm call is exactly what POST /api/bot/users/link-telegram does, and the polling
     * endpoint the step uses must then report linked so the page advances.
     */
    public function testLinkingDuringOnboardingIsReflectedByStatusEndpoint(): void
    {
        $this->enableTelegram('MyEventBot');
        $user = $this->makeUser('midflow', null);
        $this->client->loginUser($user);

        $this->client->request('GET', '/onboarding/telegram');
        $pending = $this->em->getRepository(TelegramLinkRequest::class)->findOneBy(['user' => $user]);

        static::getContainer()->get(TelegramLinkService::class)->confirm($pending->getCode(), '555777', '@midflow');

        $this->client->request('GET', '/onboarding/telegram/status');
        self::assertJsonStringEqualsJsonString('{"linked":true}', $this->client->getResponse()->getContent());

        $this->em->clear();
        self::assertTrue($this->em->getRepository(User::class)->find($user->getId())->isTelegramLinked());
    }

    /** Staff finish on their availability, which the planners build the roster from. */
    /**
     * Staff get the baseline group too, the same as SSO already grants it.
     *
     * The positional groups staff hold are not supersets of the baseline, so withholding it left a
     * locally-onboarded staff member unable to reach the page sign-in sends them to, while the same
     * person arriving through SSO could.
     */
    public function testStaffGetTheStaffTypeAndTheBaselineGroup(): void
    {
        $staffType = (new VolunteerType('Staff'))->setRole(VolunteerType::ROLE_STAFF);
        $volunteerGroup = new Group('Volunteer', 'volunteer', null);
        $this->em->persist($staffType);
        $this->em->persist($volunteerGroup);
        $this->em->flush();

        $user = $this->makeUser('chief', 'ROLE_STAFF');
        $this->client->loginUser($user);

        $this->client->request('POST', '/onboarding', ['consent' => '1']);
        $this->client->request('POST', '/onboarding/profile');
        $this->client->request('POST', '/onboarding/telegram');
        $this->client->request('POST', '/onboarding/notifications', ['show_email' => '1']);
        $this->client->request('POST', '/onboarding/theme', ['theme' => 'dark']);
        $this->client->request('POST', '/onboarding/finish', ['password' => 'newpassword1', 'password_confirm' => 'newpassword1']);
        self::assertResponseRedirects('/availability');

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());

        $membership = $this->em->getRepository(UserVolunteerType::class)
            ->findOneBy(['user' => $reloaded, 'volunteerType' => $staffType->getId()]);
        self::assertNotNull($membership);
        self::assertTrue($membership->isConfirmed());

        $groupSlugs = array_map(static fn (Group $g): string => $g->getSlug(), $reloaded->getGroups()->toArray());
        self::assertContains('volunteer', $groupSlugs, 'the baseline group is not gated on staff status');
    }

    /**
     * Renaming the base type must not stop onboarding handing it out.
     *
     * The lookup used to match the English name, which an administrator is free to change - this
     * event calls its volunteers Critters. The rename made the lookup miss, the assignment was
     * skipped in silence, and the user finished onboarding with no type and no way to be rostered.
     */
    public function testTheDefaultTypeIsAssignedEvenWhenItHasBeenRenamed(): void
    {
        $renamed = (new VolunteerType('Critter'))->setRole(VolunteerType::ROLE_VOLUNTEER);
        $this->em->persist($renamed);
        $this->em->persist(new Group('Volunteer', 'volunteer', null));
        $this->em->flush();

        $user = $this->makeUser('critter-newbie', null);
        $this->client->loginUser($user);

        $this->client->request('POST', '/onboarding', ['consent' => '1']);
        $this->client->request('POST', '/onboarding/profile', ['pronoun' => 'they/them', 'mobile' => '12345']);
        $this->client->request('POST', '/onboarding/telegram');
        $this->client->request('POST', '/onboarding/notifications', ['email_shifts' => '1', 'show_name' => '1', 'show_email' => '1']);
        $this->client->request('POST', '/onboarding/theme', ['theme' => 'dark']);
        $this->client->request('POST', '/onboarding/finish', ['password' => 'newpassword1', 'password_confirm' => 'newpassword1']);

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        self::assertTrue($reloaded->isOnboardingCompleted());

        $membership = $this->em->getRepository(UserVolunteerType::class)
            ->findOneBy(['user' => $reloaded, 'volunteerType' => $renamed->getId()]);
        self::assertNotNull($membership, 'the renamed base type is still the one onboarding assigns');
        self::assertTrue($membership->isConfirmed());
    }

}
