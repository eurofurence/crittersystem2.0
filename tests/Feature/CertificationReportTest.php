<?php

namespace App\Tests\Feature;

use App\Entity\Certification;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Entity\UserCertification;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The certification reports: one certification's holders as a spreadsheet, who across the event is
 * short of a certification their role requires, and the counts on the overview.
 *
 * The compliance report is the instrument for deciding whether the requirement can be enforced on
 * sign-up at all, so what counts as a gap has to be exactly right: an expired certification is a
 * gap, an unconfirmed membership is not.
 */
final class CertificationReportTest extends DatabaseWebTestCase
{
    private function login(array $privileges = ['certification:manage']): User
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

    private function volunteer(string $name): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.'-'.bin2hex(random_bytes(2)).'@example.com');
        $user->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword('x');
        $user->completeOnboarding();
        $this->em->persist($user);

        return $user;
    }

    private function member(User $user, VolunteerType $type, bool $confirmed = true): UserVolunteerType
    {
        $membership = new UserVolunteerType($user, $type);
        if ($confirmed) {
            $membership->setConfirmedBy($user);
        }
        $this->em->persist($membership);

        return $membership;
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

    public function testTheHolderExportListsEveryRecord(): void
    {
        $this->login();
        $certification = new Certification('Food handling');
        $this->em->persist($certification);
        $this->record($this->volunteer('holder'), $certification, UserCertification::STATUS_APPROVED, '+1 year');
        $this->record($this->volunteer('applicant'), $certification, UserCertification::STATUS_PENDING);
        $this->em->flush();

        $this->client->request('GET', '/manage/certifications/'.$certification->getUuid().'/export.csv');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Volunteer,Status,Certified,Expires', $csv);
        self::assertStringContainsString('holder', $csv);
        self::assertStringContainsString('applicant', $csv);
        self::assertStringContainsString('certification-food-handling.csv', $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    /** A spreadsheet of who is qualified travels further than a page: it is a data export. */
    public function testExportsAreAudited(): void
    {
        $this->login();
        $certification = new Certification('Food handling');
        $this->em->persist($certification);
        $this->em->flush();

        $this->client->request('GET', '/manage/certifications/'.$certification->getUuid().'/export.csv');

        self::assertNotEmpty(
            $this->em->getRepository(\App\Entity\AuditEvent::class)->findBy(['eventType' => 'DATA_EXPORT']),
        );
    }

    public function testComplianceNamesWhoIsShortOfWhat(): void
    {
        $this->login();
        $certification = new Certification('Food handling');
        $type = new VolunteerType('Kitchen');
        $type->addCertification($certification);
        $this->em->persist($certification);
        $this->em->persist($type);

        $covered = $this->volunteer('hasitalready');
        $short = $this->volunteer('short');
        $this->member($covered, $type);
        $this->member($short, $type);
        $this->record($covered, $certification, UserCertification::STATUS_APPROVED, '+1 year');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/manage/certifications/compliance');

        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('short', $body);
        self::assertStringNotContainsString('hasitalready', $body, 'somebody who holds it is not a gap');
    }

    /** An expired certification is exactly the gap this report exists to surface. */
    public function testAnExpiredCertificationCountsAsMissing(): void
    {
        $this->login();
        $certification = new Certification('Food handling');
        $type = new VolunteerType('Kitchen');
        $type->addCertification($certification);
        $this->em->persist($certification);
        $this->em->persist($type);

        $lapsed = $this->volunteer('lapsed');
        $this->member($lapsed, $type);
        $this->record($lapsed, $certification, UserCertification::STATUS_APPROVED, '-1 day');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/manage/certifications/compliance');

        self::assertStringContainsString('lapsed', $crawler->filter('body')->text());
    }

    /** Somebody whose membership was never confirmed cannot take the role, so they are not a gap. */
    public function testAnUnconfirmedMembershipIsNotCounted(): void
    {
        $this->login();
        $certification = new Certification('Food handling');
        $type = new VolunteerType('Kitchen');
        $type->addCertification($certification);
        $this->em->persist($certification);
        $this->em->persist($type);

        $waiting = $this->volunteer('waiting');
        $this->member($waiting, $type, confirmed: false);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/manage/certifications/compliance');

        self::assertStringNotContainsString('waiting', $crawler->filter('body')->text());
    }

    public function testComplianceExportsTheGapsAsCsv(): void
    {
        $this->login();
        $certification = new Certification('Food handling');
        $type = new VolunteerType('Kitchen');
        $type->addCertification($certification);
        $this->em->persist($certification);
        $this->em->persist($type);
        $this->member($this->volunteer('short'), $type);
        $this->em->flush();

        $this->client->request('GET', '/manage/certifications/compliance.csv');

        self::assertResponseIsSuccessful();
        $csv = $this->client->getResponse()->getContent();
        self::assertStringContainsString('"Volunteer type",Volunteer,Missing', $csv);
        self::assertStringContainsString('Kitchen', $csv);
        self::assertStringContainsString('short', $csv);
        self::assertStringContainsString('Food handling', $csv);
    }

    public function testARoleThatRequiresNothingIsNotReported(): void
    {
        $this->login();
        $type = new VolunteerType('Greeter');
        $this->em->persist($type);
        $this->member($this->volunteer('greeter'), $type);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/manage/certifications/compliance');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Greeter', $crawler->filter('body')->text());
    }

    /** "Qualified now" has to mean now: an approval past its expiry is not one. */
    public function testTheOverviewCountsWhatIsValidToday(): void
    {
        $this->login();
        $certification = new Certification('Food handling');
        $this->em->persist($certification);
        $this->record($this->volunteer('current'), $certification, UserCertification::STATUS_APPROVED, '+1 year');
        $this->record($this->volunteer('lapsed'), $certification, UserCertification::STATUS_APPROVED, '-1 day');
        $this->record($this->volunteer('soon'), $certification, UserCertification::STATUS_APPROVED, '+10 days');
        $this->record($this->volunteer('applicant'), $certification, UserCertification::STATUS_PENDING);
        $this->record($this->volunteer('gone'), $certification, UserCertification::STATUS_REVOKED);
        $this->em->flush();

        $stats = static::getContainer()->get(\App\Repository\UserCertificationRepository::class)->statistics();

        self::assertSame(2, $stats['held'], 'current and soon-to-expire are both held today');
        self::assertSame(1, $stats['expiring'], 'only the one inside 30 days');
        self::assertSame(1, $stats['expired']);
        self::assertSame(1, $stats['applications']);
        self::assertSame(1, $stats['revoked']);
    }

    public function testTheReportsNeedTheCertificationPrivilege(): void
    {
        $this->login(['user:view']);

        $this->client->request('GET', '/manage/certifications/compliance');

        self::assertResponseStatusCodeSame(403);
    }
}
