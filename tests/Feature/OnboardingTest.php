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

    public function testTelegramStepAutoSkipsWhenFeatureDisabled(): void
    {
        // No TelegramConfiguration persisted → feature off → the step is a dead
        // screen, so onboarding must jump straight past it.
        $this->client->loginUser($this->makeUser('nolink', null));
        $this->client->request('GET', '/onboarding/telegram');
        self::assertResponseRedirects('/onboarding/notifications');
    }

    public function testTelegramStepShowsDeepLinkButtonWhenEnabled(): void
    {
        $this->enableTelegram('MyEventBot');
        $user = $this->makeUser('linker', null);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/onboarding/telegram');
        self::assertResponseIsSuccessful();

        // A pending code was generated and the one-tap button points at it.
        $pending = $this->em->getRepository(TelegramLinkRequest::class)->findOneBy(['user' => $user]);
        self::assertNotNull($pending);
        $href = $crawler->filter('a[href^="https://t.me/"]')->attr('href');
        self::assertSame('https://t.me/MyEventBot?start='.$pending->getCode(), $href);
    }

    public function testLinkingDuringOnboardingIsReflectedByStatusEndpoint(): void
    {
        $this->enableTelegram('MyEventBot');
        $user = $this->makeUser('midflow', null);
        $this->client->loginUser($user);

        // Visiting the step issues the code the volunteer would send to the bot.
        $this->client->request('GET', '/onboarding/telegram');
        $pending = $this->em->getRepository(TelegramLinkRequest::class)->findOneBy(['user' => $user]);

        // The bot confirms it - exactly what POST /api/bot/users/link-telegram does.
        static::getContainer()->get(TelegramLinkService::class)->confirm($pending->getCode(), '555777', '@midflow');

        // The polling endpoint the step uses now reports linked, so the page advances.
        $this->client->request('GET', '/onboarding/telegram/status');
        self::assertJsonStringEqualsJsonString('{"linked":true}', $this->client->getResponse()->getContent());

        $this->em->clear();
        self::assertTrue($this->em->getRepository(User::class)->find($user->getId())->isTelegramLinked());
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
