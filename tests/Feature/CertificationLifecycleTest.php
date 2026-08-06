<?php

namespace App\Tests\Feature;

use App\Entity\Certification;
use App\Entity\Group;
use App\Entity\Notification;
use App\Entity\User;
use App\Entity\UserCertification;
use App\Service\CertificationService;
use App\Tests\DatabaseWebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * What happens to a certification as time passes: the warning before it runs out, the record of it
 * having run out, renewing it, and taking back an application nobody has decided yet.
 *
 * The pages work out expiry for themselves, so none of this changes what is on screen. What it
 * carries is the telling: an expiry passes at an instant nobody is watching, and a volunteer who is
 * never told turns up to a shift still believing they are qualified.
 */
final class CertificationLifecycleTest extends DatabaseWebTestCase
{
    private function volunteer(string $name = 'vol'): User
    {
        $group = new Group('V-'.bin2hex(random_bytes(2)), 'v-'.bin2hex(random_bytes(3)), 'ROLE_USER');
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName($name.'-'.bin2hex(random_bytes(3)))->setEmail($name.'-'.bin2hex(random_bytes(3)).'@example.com');
        $user->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);

        return $user;
    }

    private function certification(string $title = 'Food handling'): Certification
    {
        $certification = new Certification($title);
        $certification->setValidityPeriodDays(365);
        $this->em->persist($certification);

        return $certification;
    }

    private function record(User $user, Certification $certification, string $status, ?string $expires = null): UserCertification
    {
        $record = new UserCertification($user, $certification);
        $record->setStatus($status)->setDateCertified(new \DateTimeImmutable('-1 month'));
        if ($expires !== null) {
            $record->setDateExpires(new \DateTimeImmutable($expires));
        }
        $this->em->persist($record);

        return $record;
    }

    private function service(): CertificationService
    {
        return static::getContainer()->get(CertificationService::class);
    }

    private function reload(UserCertification $record): UserCertification
    {
        $id = $record->getId();
        $this->em->clear();

        return $this->em->getRepository(UserCertification::class)->find($id);
    }

    public function testALapsedCertificationIsRecordedAsExpiredAndTheHolderIsTold(): void
    {
        $volunteer = $this->volunteer();
        $certification = $this->certification();
        $record = $this->record($volunteer, $certification, UserCertification::STATUS_APPROVED, '-1 day');
        $this->em->flush();
        $volunteerId = $volunteer->getId();

        self::assertSame(1, $this->service()->markExpired());

        self::assertSame(UserCertification::STATUS_EXPIRED, $this->reload($record)->getStatus());
        $notifications = $this->em->getRepository(Notification::class)->findBy(['user' => $volunteerId]);
        self::assertCount(1, $notifications);
        self::assertStringContainsString('expired', strtolower($notifications[0]->getMessage()));
    }

    public function testACurrentCertificationIsLeftAlone(): void
    {
        $volunteer = $this->volunteer();
        $certification = $this->certification();
        $record = $this->record($volunteer, $certification, UserCertification::STATUS_APPROVED, '+1 year');
        $this->em->flush();

        self::assertSame(0, $this->service()->markExpired());
        self::assertSame(UserCertification::STATUS_APPROVED, $this->reload($record)->getStatus());
    }

    /** Running the job twice in a day must not tell somebody twice that the same thing happened. */
    public function testMarkingExpiredIsNotRepeatedOnASecondRun(): void
    {
        $volunteer = $this->volunteer();
        $certification = $this->certification();
        $this->record($volunteer, $certification, UserCertification::STATUS_APPROVED, '-1 day');
        $this->em->flush();
        $volunteerId = $volunteer->getId();

        $this->service()->markExpired();
        self::assertSame(0, $this->service()->markExpired(), 'nothing is left to expire');

        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(Notification::class)->findBy(['user' => $volunteerId]));
    }

    public function testTheHolderIsWarnedBeforeItRunsOut(): void
    {
        $volunteer = $this->volunteer();
        $certification = $this->certification();
        $record = $this->record($volunteer, $certification, UserCertification::STATUS_APPROVED, '+10 days');
        $this->em->flush();
        $volunteerId = $volunteer->getId();

        self::assertSame(1, $this->service()->remindExpiring(30));

        self::assertNotNull($this->reload($record)->getExpiryRemindedAt());
        $notifications = $this->em->getRepository(Notification::class)->findBy(['user' => $volunteerId]);
        self::assertCount(1, $notifications);
        self::assertStringContainsString('expires', strtolower($notifications[0]->getMessage()));
    }

    public function testSomethingExpiringBeyondTheWindowIsNotWarnedAbout(): void
    {
        $volunteer = $this->volunteer();
        $certification = $this->certification();
        $this->record($volunteer, $certification, UserCertification::STATUS_APPROVED, '+90 days');
        $this->em->flush();

        self::assertSame(0, $this->service()->remindExpiring(30));
    }

    /** Warned once per period, not once per night the job runs. */
    public function testTheWarningIsSentOnlyOnce(): void
    {
        $volunteer = $this->volunteer();
        $certification = $this->certification();
        $this->record($volunteer, $certification, UserCertification::STATUS_APPROVED, '+10 days');
        $this->em->flush();
        $volunteerId = $volunteer->getId();

        $this->service()->remindExpiring(30);
        self::assertSame(0, $this->service()->remindExpiring(30));

        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(Notification::class)->findBy(['user' => $volunteerId]));
    }

    /** A renewed certification runs to a new expiry, and that one is worth warning about again. */
    public function testRenewingClearsTheWarningSoTheNextPeriodWarnsAgain(): void
    {
        $volunteer = $this->volunteer();
        $certification = $this->certification();
        $record = $this->record($volunteer, $certification, UserCertification::STATUS_APPROVED, '+10 days');
        $this->em->flush();

        $this->service()->remindExpiring(30);
        self::assertNotNull($this->reload($record)->getExpiryRemindedAt());

        $fresh = $this->em->getRepository(UserCertification::class)->find($record->getId());
        $this->service()->approve($fresh, null, null);

        self::assertNull($this->reload($record)->getExpiryRemindedAt());
    }

    public function testTheCommandRunsBothSteps(): void
    {
        $volunteer = $this->volunteer();
        $certification = $this->certification();
        $this->record($volunteer, $certification, UserCertification::STATUS_APPROVED, '-1 day');
        $this->record($this->volunteer('other'), $certification, UserCertification::STATUS_APPROVED, '+5 days');
        $this->em->flush();

        $application = new Application(static::$kernel);
        $tester = new CommandTester($application->find('app:certifications:lifecycle'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $output = $tester->getDisplay();
        self::assertStringContainsString('Marked 1', $output);
        self::assertStringContainsString('Warned 1', $output);
    }

    public function testAVolunteerCanWithdrawTheirOwnApplication(): void
    {
        $volunteer = $this->volunteer();
        $certification = $this->certification();
        $this->record($volunteer, $certification, UserCertification::STATUS_PENDING);
        $this->em->flush();
        $this->client->loginUser($volunteer);

        $crawler = $this->client->request('GET', '/certifications');
        $form = $crawler->filter('form[action*="/withdraw"]')->first();
        $this->client->request('POST', $form->attr('action'), ['_token' => $form->filter('input[name="_token"]')->attr('value')]);

        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(UserCertification::class)->findAll(), 'the application is gone');
    }

    /** Once a manager has decided, the decision is not the volunteer's to remove. */
    public function testADecidedRecordCannotBeWithdrawn(): void
    {
        $volunteer = $this->volunteer();
        $certification = $this->certification();
        $this->record($volunteer, $certification, UserCertification::STATUS_APPROVED, '+1 year');
        $this->em->flush();
        $this->client->loginUser($volunteer);

        $crawler = $this->client->request('GET', '/certifications');
        self::assertCount(0, $crawler->filter('form[action*="/withdraw"]'), 'not offered');

        $this->client->request('POST', '/certifications/'.$certification->getUuid().'/withdraw', [
            '_token' => 'irrelevant',
        ]);

        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(UserCertification::class)->findAll());
    }

    public function testAnExpiredCertificationCanBeRenewedByApplyingAgain(): void
    {
        $volunteer = $this->volunteer();
        $certification = $this->certification();
        $record = $this->record($volunteer, $certification, UserCertification::STATUS_EXPIRED, '-1 day');
        $this->em->flush();
        $this->client->loginUser($volunteer);

        $crawler = $this->client->request('GET', '/certifications');
        $form = $crawler->filter('form[action*="/apply"]')->first();
        $this->client->request('POST', $form->attr('action'), ['_token' => $form->filter('input[name="_token"]')->attr('value')]);

        self::assertSame(UserCertification::STATUS_PENDING, $this->reload($record)->getStatus());
    }

    /** Somebody decided to take it away; asking again goes through them, not round them. */
    public function testARevokedCertificationCannotBeReappliedFor(): void
    {
        $volunteer = $this->volunteer();
        $certification = $this->certification();
        $record = $this->record($volunteer, $certification, UserCertification::STATUS_REVOKED);
        $this->em->flush();
        $this->client->loginUser($volunteer);

        $crawler = $this->client->request('GET', '/certifications');
        self::assertCount(0, $crawler->filter('form[action*="/apply"]'), 'no way back in from here');
        self::assertSame(UserCertification::STATUS_REVOKED, $this->reload($record)->getStatus());
    }

    public function testAVolunteerExportsTheirOwnRecordsAndOnlyThose(): void
    {
        $volunteer = $this->volunteer('mine');
        $stranger = $this->volunteer('theirs');
        $mine = $this->certification('Food handling');
        $theirs = $this->certification('Height safety');
        $this->record($volunteer, $mine, UserCertification::STATUS_APPROVED, '+1 year');
        $this->record($stranger, $theirs, UserCertification::STATUS_APPROVED, '+1 year');
        $this->em->flush();
        $this->client->loginUser($volunteer);

        $this->client->request('GET', '/certifications/export.csv');

        self::assertResponseIsSuccessful();
        $csv = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Food handling', $csv);
        self::assertStringNotContainsString('Height safety', $csv, "another volunteer's certification is not in my export");
    }
}
