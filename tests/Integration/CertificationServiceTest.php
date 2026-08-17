<?php

namespace App\Tests\Integration;

use App\Entity\Certification;
use App\Entity\User;
use App\Entity\UserCertification;
use App\Service\CertificationService;
use App\Tests\DatabaseTestCase;

final class CertificationServiceTest extends DatabaseTestCase
{
    private function service(): CertificationService
    {
        return static::getContainer()->get(CertificationService::class);
    }

    private function user(string $name): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($user);

        return $user;
    }

    private function certification(string $title, ?int $validityDays = 365, bool $allowSelf = false, bool $perpetual = false): Certification
    {
        $cert = new Certification($title);
        $cert->setIsActive(true)
            ->setIsPerpetual($perpetual)
            ->setValidityPeriodDays($perpetual ? null : $validityDays)
            ->setAllowSelfConfirmation($allowSelf);
        $this->em->persist($cert);

        return $cert;
    }

    public function testApplyCreatesPendingRecordButOnlyOncePerUser(): void
    {
        $user = $this->user('alex');
        $cert = $this->certification('First Aid');
        $this->em->flush();

        $first = $this->service()->applyFor($user, $cert);
        self::assertNotNull($first);
        self::assertSame(UserCertification::STATUS_PENDING, $first->getStatus());

        self::assertNull($this->service()->applyFor($user, $cert));
        self::assertCount(1, $this->em->getRepository(UserCertification::class)->findAll());
    }

    /**
     * A scan only approves an application that is already pending, and dates the expiry exactly
     * `validityDays` on. A record that is already valid is never approved a second time.
     */
    public function testApproveByQrRequiresPendingAndSetsExpiryFromValidityDays(): void
    {
        $service = $this->service();
        $user = $this->user('bea');
        $cert = $this->certification('Forklift', validityDays: 30);
        $this->em->flush();

        $error = $service->approveByQr($user, $cert);
        self::assertArrayHasKey('error', $error);

        $service->applyFor($user, $cert);
        $result = $service->approveByQr($user, $cert);
        $record = $result['record'];
        self::assertSame(UserCertification::STATUS_APPROVED, $record->getStatus());
        self::assertNotNull($record->getDateCertified());
        $expected = $record->getDateCertified()->modify('+30 days');
        self::assertSame($expected->format('Y-m-d'), $record->getDateExpires()?->format('Y-m-d'));

        $second = $service->approveByQr($user, $cert);
        self::assertArrayHasKey('error', $second);
    }

    public function testSelfConfirmOnlyWhenAllowedAndPerpetualHasNoExpiry(): void
    {
        $service = $this->service();
        $user = $this->user('cara');
        $closed = $this->certification('Closed', allowSelf: false);
        $open = $this->certification('Perpetual perk', allowSelf: true, perpetual: true);
        $this->em->flush();

        self::assertNull($service->selfConfirm($user, $closed));

        $record = $service->selfConfirm($user, $open);
        self::assertNotNull($record);
        self::assertSame(UserCertification::STATUS_SELF_CONFIRMED, $record->getStatus());
        self::assertNull($record->getDateExpires());
    }

    public function testQrTokenIsReusedWhileActiveAndRefreshIssuesNew(): void
    {
        $service = $this->service();
        $cert = $this->certification('Generic');
        $this->em->flush();

        $a = $service->getOrCreateToken($cert);
        $b = $service->getOrCreateToken($cert);
        self::assertSame($a->getId(), $b->getId());

        $c = $service->refreshToken($cert);
        self::assertNotSame($a->getId(), $c->getId());
        self::assertNotNull($service->findActiveToken($c->getToken()));
    }
}
