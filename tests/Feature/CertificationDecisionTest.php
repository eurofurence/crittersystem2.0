<?php

namespace App\Tests\Feature;

use App\Entity\Certification;
use App\Entity\Group;
use App\Entity\Notification;
use App\Entity\Privilege;
use App\Entity\User;
use App\Entity\UserCertification;
use App\Service\CertificationService;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Deciding on a certification: approve, decline, revoke, and what the volunteer is told.
 *
 * A decision changes what somebody is allowed to do at the event, so each one has to reach them and
 * has to be refusable only with a reason they can act on.
 */
final class CertificationDecisionTest extends DatabaseWebTestCase
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

    private function volunteer(string $name = 'vol'): User
    {
        $user = new User();
        $user->setName($name.'-'.bin2hex(random_bytes(2)))->setEmail($name.'-'.bin2hex(random_bytes(3)).'@example.com');
        $user->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword('x');
        $user->completeOnboarding();
        $this->em->persist($user);

        return $user;
    }

    /** @return array{0: Certification, 1: User, 2: UserCertification} */
    private function scenario(string $status = UserCertification::STATUS_PENDING): array
    {
        $certification = new Certification('Food handling');
        $certification->setValidityPeriodDays(365);
        $volunteer = $this->volunteer();
        $record = new UserCertification($volunteer, $certification);
        $record->setStatus($status);
        if (\in_array($status, [UserCertification::STATUS_APPROVED, UserCertification::STATUS_SELF_CONFIRMED], true)) {
            $record->setDateCertified(new \DateTimeImmutable())->setDateExpires(new \DateTimeImmutable('+1 year'));
        }
        $this->em->persist($certification);
        $this->em->persist($record);
        $this->em->flush();

        return [$certification, $volunteer, $record];
    }

    /**
     * The token is read from the rendered decision form rather than minted: it is issued per session,
     * and taking it from the page is also the only way to be sure the form carries a usable one.
     */
    private function tokenFor(Certification $certification, User $volunteer): string
    {
        $crawler = $this->client->request('GET', '/manage/certifications/'.$certification->getUuid());

        return $crawler
            ->filter(\sprintf('form[action*="/holders/%s/"] input[name="_token"]', $volunteer->getUuid()))
            ->first()
            ->attr('value');
    }

    private function decide(string $route, Certification $certification, User $volunteer, array $payload = [], ?string $token = null): void
    {
        $this->client->request('POST', \sprintf('/manage/certifications/%s/holders/%s/%s', $certification->getUuid(), $volunteer->getUuid(), $route), array_merge([
            '_token' => $token ?? $this->tokenFor($certification, $volunteer),
        ], $payload));
    }

    private function reload(Certification $certification, User $volunteer): UserCertification
    {
        $this->em->clear();

        return $this->em->getRepository(UserCertification::class)->findOneBy([
            'certification' => $certification->getId(),
            'user' => $volunteer->getId(),
        ]);
    }

    public function testApprovingGrantsTheCertificationAndSetsItsExpiry(): void
    {
        $this->login(['certification:manage', 'certification:approve']);
        [$certification, $volunteer] = $this->scenario();

        $this->decide('approve', $certification, $volunteer);

        $record = $this->reload($certification, $volunteer);
        self::assertSame(UserCertification::STATUS_APPROVED, $record->getStatus());
        self::assertNotNull($record->getDateCertified());
        self::assertNotNull($record->getDateExpires(), 'a validity period produces an expiry');
        self::assertNotNull($record->getCertifiedBy(), 'the deciding manager is recorded');
    }

    public function testDecliningKeepsTheReasonOnTheRecord(): void
    {
        $this->login(['certification:manage', 'certification:approve']);
        [$certification, $volunteer] = $this->scenario();

        $this->decide('reject', $certification, $volunteer, ['reason' => 'no certificate shown']);

        $record = $this->reload($certification, $volunteer);
        self::assertSame(UserCertification::STATUS_REJECTED, $record->getStatus());
        self::assertSame('no certificate shown', $record->getDecisionReason());
        self::assertNotNull($record->getDecidedAt());
    }

    /** A refusal the volunteer cannot act on is not a decision, so a blank reason is refused. */
    public function testDecliningWithoutAReasonIsRefused(): void
    {
        $this->login(['certification:manage', 'certification:approve']);
        [$certification, $volunteer] = $this->scenario();

        $this->decide('reject', $certification, $volunteer, ['reason' => '   ']);

        self::assertSame(UserCertification::STATUS_PENDING, $this->reload($certification, $volunteer)->getStatus());
    }

    public function testRevokingWithoutAReasonIsRefused(): void
    {
        $this->login(['certification:manage', 'certification:approve']);
        [$certification, $volunteer] = $this->scenario(UserCertification::STATUS_APPROVED);

        $this->decide('revoke', $certification, $volunteer, ['reason' => '']);

        self::assertSame(UserCertification::STATUS_APPROVED, $this->reload($certification, $volunteer)->getStatus());
    }

    public function testRevokingTakesTheCertificationAway(): void
    {
        $this->login(['certification:manage', 'certification:approve']);
        [$certification, $volunteer] = $this->scenario(UserCertification::STATUS_APPROVED);

        $this->decide('revoke', $certification, $volunteer, ['reason' => 'card expired']);

        $record = $this->reload($certification, $volunteer);
        self::assertSame(UserCertification::STATUS_REVOKED, $record->getStatus());
        self::assertFalse($record->isValid(), 'a revoked record no longer counts as qualified');
        self::assertSame('card expired', $record->getDecisionReason());
    }

    /** The volunteer has to hear about it: this is the only signal that they are not qualified. */
    public function testEachDecisionNotifiesTheVolunteer(): void
    {
        $this->login(['certification:manage', 'certification:approve']);
        [$certification, $volunteer] = $this->scenario();
        $volunteerId = $volunteer->getId();

        $this->decide('reject', $certification, $volunteer, ['reason' => 'no certificate shown']);

        $this->em->clear();
        $notifications = $this->em->getRepository(Notification::class)->findBy(['user' => $volunteerId]);
        self::assertCount(1, $notifications);
        self::assertStringContainsString('no certificate shown', $notifications[0]->getMessage());
    }

    public function testADecisionIsAudited(): void
    {
        $this->login(['certification:manage', 'certification:approve']);
        [$certification, $volunteer] = $this->scenario();

        $this->decide('approve', $certification, $volunteer);

        $events = $this->em->getRepository(\App\Entity\AuditEvent::class)->findBy(['eventType' => 'CERTIFICATION']);
        self::assertNotEmpty($events, 'granting a certification leaves an audit entry');
    }

    /** Re-applying after a refusal returns the record to the queue, carrying the earlier decision. */
    public function testAVolunteerMayApplyAgainAfterBeingDeclined(): void
    {
        $this->login(['certification:manage', 'certification:approve']);
        [$certification, $volunteer] = $this->scenario();
        $this->decide('reject', $certification, $volunteer, ['reason' => 'no certificate shown']);

        $this->em->clear();
        $fresh = $this->em->getRepository(User::class)->find($volunteer->getId());
        $cert = $this->em->getRepository(Certification::class)->find($certification->getId());
        $this->client->loginUser($fresh);

        // Submitting the volunteer's own apply form, so the re-application goes through exactly the
        // route a declined volunteer would use.
        $crawler = $this->client->request('GET', '/certifications');
        $form = $crawler->filter('form[action*="/apply"]')->first();
        $this->client->request('POST', $form->attr('action'), [
            '_token' => $form->filter('input[name="_token"]')->attr('value'),
        ]);

        $record = $this->reload($certification, $volunteer);
        self::assertSame(UserCertification::STATUS_PENDING, $record->getStatus(), 'the application is back in the queue');
        self::assertSame('no certificate shown', $record->getDecisionReason(), 'and still says why it was refused before');
    }

    public function testTheQueueListsPendingApplicationsOldestFirst(): void
    {
        $this->login(['certification:manage', 'certification:approve']);
        [$certification, $volunteer] = $this->scenario();

        $crawler = $this->client->request('GET', '/manage/certifications/queue');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($volunteer->getName(), $crawler->filter('body')->text());
        self::assertStringContainsString($certification->getTitle(), $crawler->filter('body')->text());
    }

    /** Editing what a certification is and deciding who holds it are different jobs. */
    public function testDecidingNeedsTheApprovePrivilege(): void
    {
        $this->login(['certification:manage']);
        [$certification, $volunteer] = $this->scenario();

        $this->decide('approve', $certification, $volunteer, [], 'irrelevant');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(UserCertification::STATUS_PENDING, $this->reload($certification, $volunteer)->getStatus());
    }

    public function testAForgedRequestWithoutATokenChangesNothing(): void
    {
        $this->login(['certification:manage', 'certification:approve']);
        [$certification, $volunteer] = $this->scenario();

        $this->client->request('POST', \sprintf('/manage/certifications/%s/holders/%s/approve', $certification->getUuid(), $volunteer->getUuid()));

        self::assertSame(UserCertification::STATUS_PENDING, $this->reload($certification, $volunteer)->getStatus());
    }

    /** Reinstating is the same grant: an expired or revoked record can be put back without re-applying. */
    public function testARevokedRecordCanBeReinstated(): void
    {
        $this->login(['certification:manage', 'certification:approve']);
        [$certification, $volunteer] = $this->scenario(UserCertification::STATUS_REVOKED);

        $this->decide('approve', $certification, $volunteer);

        $record = $this->reload($certification, $volunteer);
        self::assertSame(UserCertification::STATUS_APPROVED, $record->getStatus());
        self::assertTrue($record->isValid());
    }

    public function testTheHolderPageOffersDecisionsOnlyToApprovers(): void
    {
        $this->login(['certification:manage']);
        [$certification] = $this->scenario();

        $crawler = $this->client->request('GET', '/manage/certifications/'.$certification->getUuid());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action*="/approve"]'), 'no decision buttons without the privilege');
    }

    public function testTheRejectedSectionIsListedOnTheHolderPage(): void
    {
        $this->login(['certification:manage', 'certification:approve']);
        [$certification, $volunteer] = $this->scenario();
        $this->decide('reject', $certification, $volunteer, ['reason' => 'no certificate shown']);

        $crawler = $this->client->request('GET', '/manage/certifications/'.$certification->getUuid());

        self::assertCount(\count(CertificationService::HOLDER_SECTIONS), $crawler->filter('turbo-frame[id^="cert-holders-"]'));
        self::assertStringContainsString($volunteer->getName(), $crawler->filter('turbo-frame#cert-holders-rejected')->text());
    }
}
