<?php

namespace App\Tests\Feature;

use App\Entity\Certification;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Entity\UserCertification;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Deciding on several records at once, granting to several people at once, and taking a
 * certification back from everybody who holds it.
 *
 * The forms are submitted as the browser renders them, not as the controller happens to read them:
 * the user picker submits `user[]`, and reading only a scalar answered 400 on every grant made
 * through it while a test posting `user=<uuid>` stayed green.
 */
final class CertificationBulkTest extends DatabaseWebTestCase
{
    private function login(array $privileges = ['certification:manage', 'certification:approve']): User
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

    private function certification(string $title = 'Food handling'): Certification
    {
        $certification = new Certification($title);
        $certification->setValidityPeriodDays(365);
        $this->em->persist($certification);

        return $certification;
    }

    private function record(User $user, Certification $certification, string $status): UserCertification
    {
        $record = new UserCertification($user, $certification);
        $record->setStatus($status);
        if (\in_array($status, [UserCertification::STATUS_APPROVED, UserCertification::STATUS_SELF_CONFIRMED], true)) {
            $record->setDateCertified(new \DateTimeImmutable())->setDateExpires(new \DateTimeImmutable('+1 year'));
        }
        $this->em->persist($record);

        return $record;
    }

    private function statusOf(Certification $certification, User $user): ?string
    {
        return $this->em->getRepository(UserCertification::class)
            ->findOneBy(['certification' => $certification->getId(), 'user' => $user->getId()])
            ?->getStatus();
    }

    private function tokenFrom(string $url, string $formSelector): string
    {
        $crawler = $this->client->request('GET', $url);

        return $crawler->filter($formSelector.' input[name="_token"]')->first()->attr('value');
    }

    /** The picker's own field name, which is what a browser actually sends. */
    public function testGrantingThroughThePickerFieldNameWorks(): void
    {
        $this->login();
        $certification = $this->certification();
        $volunteer = $this->volunteer();
        $this->em->flush();

        $token = $this->tokenFrom('/manage/certifications/'.$certification->getUuid(), 'form[action*="/certifications/grant"]');
        $this->client->request('POST', '/manage/certifications/grant', [
            '_token' => $token,
            'certification' => (string) $certification->getUuid(),
            'user' => [(string) $volunteer->getUuid()],
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertSame(UserCertification::STATUS_APPROVED, $this->statusOf($certification, $volunteer));
    }

    public function testGrantingToSeveralPeopleAtOnce(): void
    {
        $this->login();
        $certification = $this->certification();
        $a = $this->volunteer();
        $b = $this->volunteer();
        $c = $this->volunteer();
        $this->em->flush();

        $token = $this->tokenFrom('/manage/certifications/'.$certification->getUuid(), 'form[action*="/certifications/grant"]');
        $this->client->request('POST', '/manage/certifications/grant', [
            '_token' => $token,
            'certification' => (string) $certification->getUuid(),
            'user' => [(string) $a->getUuid(), (string) $b->getUuid(), (string) $c->getUuid()],
        ]);

        $this->em->clear();
        foreach ([$a, $b, $c] as $volunteer) {
            self::assertSame(UserCertification::STATUS_APPROVED, $this->statusOf($certification, $volunteer));
        }
    }

    /** The user's own page submits one fixed volunteer rather than a picker. */
    public function testGrantingFromTheUserPageStillWorksWithAScalarField(): void
    {
        $this->login(['certification:manage', 'certification:approve', 'user:edit', 'user:view']);
        $certification = $this->certification();
        $volunteer = $this->volunteer();
        $this->em->flush();

        $token = $this->tokenFrom('/manage/users/'.$volunteer->getUuid().'/edit', 'form[action*="/certifications/grant"]');
        $this->client->request('POST', '/manage/certifications/grant', [
            '_token' => $token,
            'certification' => (string) $certification->getUuid(),
            'user' => (string) $volunteer->getUuid(),
            'from' => 'user',
        ]);

        $this->em->clear();
        self::assertSame(UserCertification::STATUS_APPROVED, $this->statusOf($certification, $volunteer));
    }

    public function testApprovingASelectionFromTheQueue(): void
    {
        $this->login();
        $first = $this->certification('First aid');
        $second = $this->certification('Food handling');
        $a = $this->volunteer();
        $b = $this->volunteer();
        $this->record($a, $first, UserCertification::STATUS_PENDING);
        $this->record($b, $second, UserCertification::STATUS_PENDING);
        $this->em->flush();

        $token = $this->tokenFrom('/manage/certifications/queue', 'form[action*="/certifications/bulk"]');
        $this->client->request('POST', '/manage/certifications/bulk', [
            '_token' => $token,
            'action' => 'approve',
            'from' => 'queue',
            'records' => [
                $first->getUuid().':'.$a->getUuid(),
                $second->getUuid().':'.$b->getUuid(),
            ],
        ]);

        $this->em->clear();
        self::assertSame(UserCertification::STATUS_APPROVED, $this->statusOf($first, $a));
        self::assertSame(UserCertification::STATUS_APPROVED, $this->statusOf($second, $b));
    }

    public function testDecliningASelectionNeedsAReason(): void
    {
        $this->login();
        $certification = $this->certification();
        $volunteer = $this->volunteer();
        $this->record($volunteer, $certification, UserCertification::STATUS_PENDING);
        $this->em->flush();

        $token = $this->tokenFrom('/manage/certifications/queue', 'form[action*="/certifications/bulk"]');
        $this->client->request('POST', '/manage/certifications/bulk', [
            '_token' => $token,
            'action' => 'reject',
            'reason' => '',
            'records' => [$certification->getUuid().':'.$volunteer->getUuid()],
        ]);

        $this->em->clear();
        self::assertSame(UserCertification::STATUS_PENDING, $this->statusOf($certification, $volunteer));
    }

    /** A list worked through in a hurry must not turn a decision into the wrong one. */
    public function testARecordTheActionDoesNotFitIsSkipped(): void
    {
        $this->login();
        $certification = $this->certification();
        $pending = $this->volunteer();
        $revoked = $this->volunteer();
        $this->record($pending, $certification, UserCertification::STATUS_PENDING);
        $this->record($revoked, $certification, UserCertification::STATUS_REVOKED);
        $this->em->flush();

        $token = $this->tokenFrom('/manage/certifications/queue', 'form[action*="/certifications/bulk"]');
        $this->client->request('POST', '/manage/certifications/bulk', [
            '_token' => $token,
            'action' => 'reject',
            'reason' => 'no certificate shown',
            'records' => [
                $certification->getUuid().':'.$pending->getUuid(),
                $certification->getUuid().':'.$revoked->getUuid(),
            ],
        ]);

        $this->em->clear();
        self::assertSame(UserCertification::STATUS_REJECTED, $this->statusOf($certification, $pending));
        self::assertSame(UserCertification::STATUS_REVOKED, $this->statusOf($certification, $revoked), 'a revoked record is not declined');
    }

    public function testRevokingFromEveryHolder(): void
    {
        $this->login();
        $certification = $this->certification();
        $held = $this->volunteer();
        $selfConfirmed = $this->volunteer();
        $applicant = $this->volunteer();
        $this->record($held, $certification, UserCertification::STATUS_APPROVED);
        $this->record($selfConfirmed, $certification, UserCertification::STATUS_SELF_CONFIRMED);
        $this->record($applicant, $certification, UserCertification::STATUS_PENDING);
        $this->em->flush();

        $token = $this->tokenFrom('/manage/certifications/'.$certification->getUuid(), 'form[action*="/revoke-all"]');
        $this->client->request('POST', '/manage/certifications/'.$certification->getUuid().'/revoke-all', [
            '_token' => $token,
            'reason' => 'the course was withdrawn',
        ]);

        $this->em->clear();
        self::assertSame(UserCertification::STATUS_REVOKED, $this->statusOf($certification, $held));
        self::assertSame(UserCertification::STATUS_REVOKED, $this->statusOf($certification, $selfConfirmed));
        self::assertSame(UserCertification::STATUS_PENDING, $this->statusOf($certification, $applicant), 'an application was never held, so there is nothing to take back');
    }

    public function testRevokingFromEveryHolderNeedsAReason(): void
    {
        $this->login();
        $certification = $this->certification();
        $held = $this->volunteer();
        $this->record($held, $certification, UserCertification::STATUS_APPROVED);
        $this->em->flush();

        $token = $this->tokenFrom('/manage/certifications/'.$certification->getUuid(), 'form[action*="/revoke-all"]');
        $this->client->request('POST', '/manage/certifications/'.$certification->getUuid().'/revoke-all', [
            '_token' => $token,
            'reason' => '  ',
        ]);

        $this->em->clear();
        self::assertSame(UserCertification::STATUS_APPROVED, $this->statusOf($certification, $held));
    }

    /** Nobody holds it, so the control that empties the list is not offered at all. */
    public function testMassRevokeIsNotOfferedWhenNobodyHoldsIt(): void
    {
        $this->login();
        $certification = $this->certification();
        $applicant = $this->volunteer();
        $this->record($applicant, $certification, UserCertification::STATUS_PENDING);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/manage/certifications/'.$certification->getUuid());

        self::assertCount(0, $crawler->filter('form[action*="/revoke-all"]'));
    }

    public function testBulkActionsNeedTheApprovePrivilege(): void
    {
        $this->login(['certification:manage']);
        $certification = $this->certification();
        $volunteer = $this->volunteer();
        $this->record($volunteer, $certification, UserCertification::STATUS_PENDING);
        $this->em->flush();

        $this->client->request('POST', '/manage/certifications/bulk', [
            '_token' => 'irrelevant',
            'action' => 'approve',
            'records' => [$certification->getUuid().':'.$volunteer->getUuid()],
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertSame(UserCertification::STATUS_PENDING, $this->statusOf($certification, $volunteer));
    }

    public function testAForgedBulkRequestChangesNothing(): void
    {
        $this->login();
        $certification = $this->certification();
        $volunteer = $this->volunteer();
        $this->record($volunteer, $certification, UserCertification::STATUS_PENDING);
        $this->em->flush();

        $this->client->request('POST', '/manage/certifications/bulk', [
            'action' => 'approve',
            'records' => [$certification->getUuid().':'.$volunteer->getUuid()],
        ]);

        $this->em->clear();
        self::assertSame(UserCertification::STATUS_PENDING, $this->statusOf($certification, $volunteer));
    }
}
