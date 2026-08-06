<?php

namespace App\Tests\Feature;

use App\Entity\Certification;
use App\Entity\Group;
use App\Entity\Notification;
use App\Entity\Privilege;
use App\Entity\User;
use App\Entity\UserCertification;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Adding a certification to somebody by hand: the case where the proof arrived on paper, or at a
 * desk, and never went through an application at all.
 *
 * The admin sets the dates, because a paper certificate carries its own and the event's validity
 * period is not what it says.
 */
final class CertificationGrantTest extends DatabaseWebTestCase
{
    private function login(array $privileges): User
    {
        $group = new Group('G-'.bin2hex(random_bytes(2)), 'g-'.bin2hex(random_bytes(3)), 'ROLE_STAFF');
        foreach ($privileges as $name) {
            $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => $name]) ?? new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('mgr-'.bin2hex(random_bytes(3)))->setEmail('mgr-'.bin2hex(random_bytes(3)).'@example.com');
        $user->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        return $user;
    }

    private function volunteer(): User
    {
        $user = new User();
        $user->setName('vol-'.bin2hex(random_bytes(3)))->setEmail('vol-'.bin2hex(random_bytes(3)).'@example.com');
        $user->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword('x');
        $user->completeOnboarding();
        $this->em->persist($user);

        return $user;
    }

    /** @return array{0: Certification, 1: User} */
    private function scenario(): array
    {
        $certification = new Certification('Food handling');
        $certification->setValidityPeriodDays(365);
        $volunteer = $this->volunteer();
        $this->em->persist($certification);
        $this->em->flush();

        return [$certification, $volunteer];
    }

    /** The token is read from the rendered form, which is where a real grant comes from. */
    private function grantToken(Certification $certification): string
    {
        $crawler = $this->client->request('GET', '/manage/certifications/'.$certification->getUuid());

        return $crawler->filter('form[action*="/certifications/grant"] input[name="_token"]')->first()->attr('value');
    }

    private function grant(Certification $certification, User $holder, array $payload = []): void
    {
        $this->client->request('POST', '/manage/certifications/grant', array_merge([
            '_token' => $this->grantToken($certification),
            'certification' => (string) $certification->getUuid(),
            'user' => (string) $holder->getUuid(),
        ], $payload));
    }

    private function reload(Certification $certification, User $volunteer): ?UserCertification
    {
        $this->em->clear();

        return $this->em->getRepository(UserCertification::class)->findOneBy([
            'certification' => $certification->getId(),
            'user' => $volunteer->getId(),
        ]);
    }

    public function testGrantingCreatesACertifiedRecord(): void
    {
        $this->login(['certification:manage', 'certification:approve']);
        [$certification, $volunteer] = $this->scenario();

        $this->grant($certification, $volunteer, ['notes' => 'paper certificate seen at the desk']);

        $record = $this->reload($certification, $volunteer);
        self::assertNotNull($record);
        self::assertSame(UserCertification::STATUS_APPROVED, $record->getStatus());
        self::assertTrue($record->isValid());
        self::assertNotNull($record->getDateCertified());
        self::assertNotNull($record->getDateExpires(), 'the validity period fills the expiry in');
        self::assertSame('paper certificate seen at the desk', $record->getNotes());
    }

    /** A paper certificate carries its own dates, and they are not the ones the event would compute. */
    public function testTheAdminsOwnDatesWin(): void
    {
        $this->login(['certification:manage', 'certification:approve']);
        [$certification, $volunteer] = $this->scenario();

        $this->grant($certification, $volunteer, [
            'date_certified' => '2025-03-04',
            'date_expires' => '2030-01-01',
        ]);

        $record = $this->reload($certification, $volunteer);
        self::assertSame('2025-03-04', $record->getDateCertified()->format('Y-m-d'), 'backdated as given');
        self::assertSame('2030-01-01', $record->getDateExpires()->format('Y-m-d'), 'not the 365-day period');
    }

    /** One row per volunteer and certification, so a lapsed record is the one being granted again. */
    public function testGrantingReplacesARevokedRecordInsteadOfFailing(): void
    {
        $this->login(['certification:manage', 'certification:approve']);
        [$certification, $volunteer] = $this->scenario();
        $old = new UserCertification($volunteer, $certification);
        $old->setStatus(UserCertification::STATUS_REVOKED)->setDecisionReason('card expired');
        $this->em->persist($old);
        $this->em->flush();

        $this->grant($certification, $volunteer);

        $record = $this->reload($certification, $volunteer);
        self::assertSame(UserCertification::STATUS_APPROVED, $record->getStatus());
        self::assertNull($record->getDecisionReason(), 'the old refusal does not sit on a record that now grants it');
        self::assertCount(1, $this->em->getRepository(UserCertification::class)->findAll());
    }

    public function testRecordingAnApplicationOnSomebodysBehalfLeavesItPending(): void
    {
        $this->login(['certification:manage', 'certification:approve']);
        [$certification, $volunteer] = $this->scenario();

        $this->grant($certification, $volunteer, ['status' => 'pending']);

        $record = $this->reload($certification, $volunteer);
        self::assertSame(UserCertification::STATUS_PENDING, $record->getStatus());
        self::assertNull($record->getDateCertified(), 'an application is not a certification');
        self::assertNull($record->getDateExpires());
    }

    public function testAGrantNotifiesTheVolunteerAndIsAudited(): void
    {
        $this->login(['certification:manage', 'certification:approve']);
        [$certification, $volunteer] = $this->scenario();
        $volunteerId = $volunteer->getId();

        $this->grant($certification, $volunteer);

        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(Notification::class)->findBy(['user' => $volunteerId]));
        self::assertNotEmpty($this->em->getRepository(\App\Entity\AuditEvent::class)->findBy(['eventType' => 'CERTIFICATION']));
    }

    /** Recording an application on someone's behalf is not news to them; only a grant is. */
    public function testRecordingAPendingApplicationDoesNotNotify(): void
    {
        $this->login(['certification:manage', 'certification:approve']);
        [$certification, $volunteer] = $this->scenario();
        $volunteerId = $volunteer->getId();

        $this->grant($certification, $volunteer, ['status' => 'pending']);

        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(Notification::class)->findBy(['user' => $volunteerId]));
    }

    public function testAnUnreadableDateChangesNothing(): void
    {
        $this->login(['certification:manage', 'certification:approve']);
        [$certification, $volunteer] = $this->scenario();

        $this->grant($certification, $volunteer, ['date_certified' => 'the day before yesterday-ish']);

        self::assertNull($this->reload($certification, $volunteer), 'nothing is written from a date that cannot be read');
    }

    public function testAMissingVolunteerChangesNothing(): void
    {
        $this->login(['certification:manage', 'certification:approve']);
        [$certification] = $this->scenario();

        $this->client->request('POST', '/manage/certifications/grant', [
            '_token' => $this->grantToken($certification),
            'certification' => (string) $certification->getUuid(),
            'user' => '',
        ]);

        self::assertCount(0, $this->em->getRepository(UserCertification::class)->findAll());
    }

    public function testGrantingNeedsTheApprovePrivilege(): void
    {
        $this->login(['certification:manage']);
        [$certification, $volunteer] = $this->scenario();

        $this->client->request('POST', '/manage/certifications/grant', [
            '_token' => 'irrelevant',
            'certification' => (string) $certification->getUuid(),
            'user' => (string) $volunteer->getUuid(),
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->em->getRepository(UserCertification::class)->findAll());
    }

    public function testAForgedGrantWithoutATokenChangesNothing(): void
    {
        $this->login(['certification:manage', 'certification:approve']);
        [$certification, $volunteer] = $this->scenario();

        $this->client->request('POST', '/manage/certifications/grant', [
            'certification' => (string) $certification->getUuid(),
            'user' => (string) $volunteer->getUuid(),
        ]);

        self::assertCount(0, $this->em->getRepository(UserCertification::class)->findAll());
    }

    /** The same grant reaches the user's own management page, with the volunteer already fixed. */
    public function testTheUserPageListsCertificationsAndOffersTheGrant(): void
    {
        $this->login(['certification:manage', 'certification:approve', 'user:edit', 'user:view']);
        [$certification, $volunteer] = $this->scenario();
        $record = new UserCertification($volunteer, $certification);
        $record->setStatus(UserCertification::STATUS_APPROVED)->setDateExpires(new \DateTimeImmutable('+1 year'));
        $this->em->persist($record);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/manage/users/'.$volunteer->getUuid().'/edit');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($certification->getTitle(), $crawler->filter('body')->text());
        self::assertCount(1, $crawler->filter('form[action*="/certifications/grant"]'));
        self::assertCount(1, $crawler->filter('form[action*="/certifications/grant"] input[name="user"]'), 'the volunteer is fixed, not picked');
    }

    public function testTheUserPageHidesCertificationsFromSomebodyWhoCannotDecide(): void
    {
        $this->login(['user:edit', 'user:view']);
        [$certification, $volunteer] = $this->scenario();
        $record = new UserCertification($volunteer, $certification);
        $record->setStatus(UserCertification::STATUS_APPROVED);
        $this->em->persist($record);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/manage/users/'.$volunteer->getUuid().'/edit');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action*="/certifications/grant"]'));
        self::assertStringNotContainsString($certification->getTitle(), $crawler->filter('body')->text());
    }

    public function testTheHolderSearchAnswersWithPublicUuids(): void
    {
        $this->login(['certification:manage', 'certification:approve']);
        $volunteer = $this->volunteer();
        $this->em->flush();

        $this->client->request('GET', '/manage/certifications/user-search?q='.substr($volunteer->getName(), 0, 5));

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNotEmpty($data['results']);
        self::assertSame((string) $volunteer->getUuid(), $data['results'][0]['id']);
    }
}
