<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Settings;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Service\EventConfigStore;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The onboarding steps added for travel plans and theme choice, and where the wizard leaves the
 * user once it is done.
 */
final class OnboardingStepsTest extends DatabaseWebTestCase
{
    /** A signed-in user who has NOT completed onboarding, so the wizard is what they get. */
    private function newcomer(string $role = 'ROLE_USER', array $privileges = ['news:view']): User
    {
        $suffix = bin2hex(random_bytes(4));
        $group = new Group('Grp '.$suffix, 'grp-'.$suffix, $role);
        foreach ($privileges as $name) {
            $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => $name]) ?? new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('new-'.$suffix)->setEmail('new-'.$suffix.'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $this->em->persist($user);

        $settings = new Settings($user);
        $user->setSettings($settings);
        $this->em->persist($settings);
        $this->em->flush();

        $this->client->loginUser($user);

        return $user;
    }

    // --- travel plans ---------------------------------------------------

    public function testTheProfileStepStoresTravelDates(): void
    {
        $user = $this->newcomer();

        $this->client->request('POST', '/onboarding/profile', [
            'pronoun' => '',
            'mobile' => '',
            'planned_arrival' => '2027-06-04',
            'planned_departure' => '2027-06-09',
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->find($user->getId());
        self::assertSame('2027-06-04', $stored->getPersonalData()?->getPlannedArrivalDate()?->format('Y-m-d'));
        self::assertSame('2027-06-09', $stored->getPersonalData()?->getPlannedDepartureDate()?->format('Y-m-d'));
    }

    /** Travel plans are optional: leaving them blank must not hold the wizard up. */
    public function testTheProfileStepAcceptsBlankTravelDates(): void
    {
        $user = $this->newcomer();

        $this->client->request('POST', '/onboarding/profile', [
            'pronoun' => '',
            'mobile' => '',
            'planned_arrival' => '',
            'planned_departure' => '',
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->find($user->getId());
        self::assertNull($stored->getPersonalData()?->getPlannedArrivalDate());
    }

    /** A date the browser never produces must not turn the step into a 500. */
    public function testTheProfileStepIgnoresAnUnparsableDate(): void
    {
        $this->newcomer();

        $this->client->request('POST', '/onboarding/profile', [
            'pronoun' => '',
            'mobile' => '',
            'planned_arrival' => 'not-a-date',
            'planned_departure' => '',
        ]);

        self::assertResponseRedirects();
    }

    // --- theme step -----------------------------------------------------

    public function testTheThemeStepIsReachableDuringOnboarding(): void
    {
        $this->newcomer();

        $this->client->request('GET', '/onboarding/theme');

        self::assertResponseIsSuccessful();
    }

    public function testTheThemeStepStoresTheChoiceAndContinues(): void
    {
        $user = $this->newcomer();

        $this->client->request('POST', '/onboarding/theme', ['theme' => 'dark']);

        self::assertResponseRedirects('/onboarding/finish');
        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->find($user->getId());
        self::assertSame('dark', $stored->getSettings()?->getTheme());
    }

    /**
     * The step cannot be failed: confirming without choosing stores the event default rather than
     * leaving the user on a screen they cannot pass.
     */
    public function testConfirmingWithoutAChoiceStoresTheEventDefault(): void
    {
        $config = static::getContainer()->get(EventConfigStore::class);
        $config->set(EventConfigStore::KEY_DEFAULT_THEME, 'eurofurence');
        $config->flush();

        $user = $this->newcomer();

        $this->client->request('POST', '/onboarding/theme', ['theme' => '']);

        self::assertResponseRedirects('/onboarding/finish');
        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->find($user->getId());
        self::assertSame('eurofurence', $stored->getSettings()?->getTheme());
    }

    /** An unknown slug is not stored verbatim; it falls back to the default. */
    public function testAnUnknownThemeSlugIsRefused(): void
    {
        $user = $this->newcomer();

        $this->client->request('POST', '/onboarding/theme', ['theme' => '../../etc/passwd']);

        self::assertResponseRedirects('/onboarding/finish');
        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->find($user->getId());
        self::assertNotSame('../../etc/passwd', $stored->getSettings()?->getTheme());
    }

    /** The preview reloads the step under the previewed theme without storing it. */
    public function testPreviewingTouchesNothing(): void
    {
        $user = $this->newcomer();

        $this->client->request('GET', '/onboarding/theme?theme=dark');

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->find($user->getId());
        self::assertNull($stored->getSettings()?->getTheme(), 'a preview must not save the theme');
    }

    /**
     * The new step is part of the wizard, not a second theme screen: someone who has already
     * finished onboarding is bounced out of it like every other step.
     */
    public function testAnAlreadyOnboardedUserCannotReenterTheThemeStep(): void
    {
        $user = $this->newcomer();
        $user->completeOnboarding();
        $this->em->flush();

        $this->client->request('GET', '/onboarding/theme');

        self::assertResponseRedirects('/news');
    }

    public function testTheNotificationsStepLeadsToTheThemeStep(): void
    {
        $user = $this->newcomer();
        $user->getContact()?->setMobile('+49 100');
        $this->client->request('POST', '/onboarding/notifications', [
            'show_email' => '1',
            'email_shifts' => '1',
        ]);

        self::assertResponseRedirects('/onboarding/theme');
    }

    // --- completion redirect --------------------------------------------

    private function volunteerType(string $name): void
    {
        if ($this->em->getRepository(VolunteerType::class)->findOneBy(['name' => $name]) === null) {
            $this->em->persist(new VolunteerType($name));
            $this->em->flush();
        }
    }

    public function testAStaffUserLandsOnAvailability(): void
    {
        $this->volunteerType('Staff');
        $this->newcomer('ROLE_STAFF');

        $this->client->request('POST', '/onboarding/finish', [
            'password' => 'secret12345',
            'password_confirm' => 'secret12345',
        ]);

        self::assertResponseRedirects('/availability');
    }

    public function testAVolunteerLandsOnTheNews(): void
    {
        $this->volunteerType('Volunteer');
        $this->newcomer();

        $this->client->request('POST', '/onboarding/finish', [
            'password' => 'secret12345',
            'password_confirm' => 'secret12345',
        ]);

        self::assertResponseRedirects('/news');
    }
}
