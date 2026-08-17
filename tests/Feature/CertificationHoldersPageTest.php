<?php

namespace App\Tests\Feature;

use App\Entity\Certification;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Entity\UserCertification;
use App\Service\CertificationService;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The certification holder page: who applied, who holds it, and what each record counts as today.
 *
 * The status a row is filed under is not the stored one. An approved certification whose expiry has
 * passed belongs with the expired records - a manager reading the approved list has to be reading a
 * list of people who are actually qualified right now.
 */
final class CertificationHoldersPageTest extends DatabaseWebTestCase
{
    private function manager(): User
    {
        $group = new Group('Cert managers', 'certmgr-'.bin2hex(random_bytes(2)), 'ROLE_STAFF');
        foreach (['certification:manage', 'certification:view'] as $name) {
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
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword('x');
        $user->completeOnboarding();
        $this->em->persist($user);

        return $user;
    }

    private function certification(): Certification
    {
        $certification = new Certification('Food handling');
        $this->em->persist($certification);

        return $certification;
    }

    private function record(User $user, Certification $certification, string $status, ?string $expires = null): UserCertification
    {
        $record = new UserCertification($user, $certification);
        $record->setStatus($status);
        if ($expires !== null) {
            $record->setDateExpires(new \DateTimeImmutable($expires));
        }
        $this->em->persist($record);

        return $record;
    }

    public function testEachStatusIsListedWithItsCount(): void
    {
        $this->manager();
        $certification = $this->certification();
        $this->record($this->volunteer('applicant'), $certification, UserCertification::STATUS_PENDING);
        $this->record($this->volunteer('holder'), $certification, UserCertification::STATUS_APPROVED, '+1 year');
        $this->record($this->volunteer('selfie'), $certification, UserCertification::STATUS_SELF_CONFIRMED, '+1 year');
        $this->record($this->volunteer('gonner'), $certification, UserCertification::STATUS_REVOKED);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/manage/certifications/'.$certification->getUuid());

        self::assertResponseIsSuccessful();
        foreach (['applicant', 'holder', 'selfie', 'gonner'] as $name) {
            self::assertStringContainsString($name, $crawler->filter('body')->text());
        }
        self::assertCount(\count(CertificationService::HOLDER_SECTIONS), $crawler->filter('turbo-frame[id^="cert-holders-"]'));
    }

    /** An approved record past its expiry is not a qualified holder and must not be filed as one. */
    public function testAnExpiredApprovalIsFiledAsExpiredNotApproved(): void
    {
        $this->manager();
        $certification = $this->certification();
        $this->record($this->volunteer('lapsed'), $certification, UserCertification::STATUS_APPROVED, '-1 day');
        $this->record($this->volunteer('current'), $certification, UserCertification::STATUS_APPROVED, '+1 day');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/manage/certifications/'.$certification->getUuid());

        $approved = $crawler->filter('turbo-frame#cert-holders-approved')->text();
        $expired = $crawler->filter('turbo-frame#cert-holders-expired')->text();

        self::assertStringContainsString('current', $approved);
        self::assertStringNotContainsString('lapsed', $approved);
        self::assertStringContainsString('lapsed', $expired);
    }

    /** Revocation is a decision about a person; the clock must not relabel it as a routine expiry. */
    public function testARevokedRecordStaysRevokedEvenWhenItsExpiryHasPassed(): void
    {
        $this->manager();
        $certification = $this->certification();
        $this->record($this->volunteer('barred'), $certification, UserCertification::STATUS_REVOKED, '-1 day');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/manage/certifications/'.$certification->getUuid());

        self::assertStringContainsString('barred', $crawler->filter('turbo-frame#cert-holders-revoked')->text());
        self::assertStringNotContainsString('barred', $crawler->filter('turbo-frame#cert-holders-expired')->text());
    }

    /** Each section searches and pages on its own parameters, so one table cannot reset another. */
    public function testSearchingOneSectionLeavesTheOthersAlone(): void
    {
        $this->manager();
        $certification = $this->certification();
        $this->record($this->volunteer('alice'), $certification, UserCertification::STATUS_PENDING);
        $this->record($this->volunteer('bob'), $certification, UserCertification::STATUS_PENDING);
        $this->record($this->volunteer('carol'), $certification, UserCertification::STATUS_APPROVED, '+1 year');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/manage/certifications/'.$certification->getUuid().'?pending_q=ali');

        $pending = $crawler->filter('turbo-frame#cert-holders-pending')->text();
        self::assertStringContainsString('alice', $pending);
        self::assertStringNotContainsString('bob', $pending);
        self::assertStringContainsString('carol', $crawler->filter('turbo-frame#cert-holders-approved')->text());
    }

    /**
     * A hand-edited URL carrying a blank page number, or a page far past the end, lands on real
     * rows rather than answering 400 or rendering an empty table.
     */
    public function testPagingIsClampedRatherThanRefused(): void
    {
        $this->manager();
        $certification = $this->certification();
        $this->record($this->volunteer('only-one'), $certification, UserCertification::STATUS_PENDING);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/manage/certifications/'.$certification->getUuid().'?pending_page=');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('only-one', $crawler->filter('turbo-frame#cert-holders-pending')->text());

        $crawler = $this->client->request('GET', '/manage/certifications/'.$certification->getUuid().'?pending_page=99');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('only-one', $crawler->filter('turbo-frame#cert-holders-pending')->text());
    }

    /** A long list pages instead of rendering every row, and page two carries the remainder. */
    public function testALongSectionPages(): void
    {
        $this->manager();
        $certification = $this->certification();
        $perPage = CertificationService::HOLDERS_PER_PAGE;
        for ($i = 1; $i <= $perPage + 5; ++$i) {
            $this->record($this->volunteer(\sprintf('vol%03d', $i)), $certification, UserCertification::STATUS_PENDING);
        }
        $this->em->flush();

        $first = $this->client->request('GET', '/manage/certifications/'.$certification->getUuid());
        self::assertCount($perPage, $first->filter('turbo-frame#cert-holders-pending tbody tr'));

        $second = $this->client->request('GET', '/manage/certifications/'.$certification->getUuid().'?pending_page=2');
        self::assertCount(5, $second->filter('turbo-frame#cert-holders-pending tbody tr'));
    }

    public function testTheHolderPageIsBehindTheCertificationPrivilege(): void
    {
        $certification = $this->certification();
        $this->em->flush();

        $group = new Group('Plain', 'plain-'.bin2hex(random_bytes(2)), 'ROLE_USER');
        $this->em->persist($group);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('plain-'.bin2hex(random_bytes(3)))->setEmail('plain-'.bin2hex(random_bytes(3)).'@example.com');
        $user->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/manage/certifications/'.$certification->getUuid());

        self::assertResponseStatusCodeSame(403);
    }
}
