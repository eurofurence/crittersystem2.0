<?php

namespace App\Tests\Feature;

use App\Entity\Certification;
use App\Entity\CertificationToken;
use App\Entity\DigitalIdToken;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Entity\UserCertification;
use App\Service\DigitalIdService;
use App\Tests\DatabaseWebTestCase;

final class QrFeaturesTest extends DatabaseWebTestCase
{
    private function user(string $name): User
    {
        $group = new Group('g'.$name, 'g-'.$name);
        $group->addPrivilege($privilege = new Privilege('news:view'));
        $this->em->persist($privilege);
        $this->em->persist($group);

        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);

        return $user;
    }

    public function testDigitalIdVerifyIsPublicAndShowsTheUser(): void
    {
        $user = $this->user('scanme');
        $token = new DigitalIdToken($user);
        $this->em->persist($token);
        $this->em->flush();

        // No login: the page must still be reachable.
        $this->client->request('GET', '/digital-id/verify/'.$token->getToken());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'scanme');
    }

    public function testDigitalIdVerifyRejectsUnknownToken(): void
    {
        $this->client->request('GET', '/digital-id/verify/'.str_repeat('0', 64));
        self::assertResponseStatusCodeSame(404);
    }

    public function testDigitalIdRotatesViaStimulusAndNotAMetaRefresh(): void
    {
        $user = $this->user('carrier');
        $this->em->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/digital-id');
        self::assertResponseIsSuccessful();

        // A <meta http-equiv="refresh"> survives Turbo navigation and would drag
        // the user back to this page long after they left it.
        self::assertCount(0, $crawler->filter('meta[http-equiv="refresh"]'), 'the page must not use a meta refresh');

        // The layout polls for notifications too, so scope to this page's poller.
        $poller = $crawler->filter('[data-live-refresh-url-value="/digital-id/card"]');
        self::assertCount(1, $poller, 'the QR card refreshes itself via the live-refresh controller');
        self::assertSame('live-refresh', $poller->attr('data-controller'));
        self::assertGreaterThan(0, (int) $poller->attr('data-live-refresh-interval-value'));
    }

    public function testDigitalIdCardEndpointReturnsTheQrOnItsOwn(): void
    {
        $user = $this->user('poller');
        $this->em->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/digital-id/card');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('img[alt="Digital ID QR code"]'));
        // A fragment, not a full page: the layout must not come along with it.
        self::assertCount(0, $crawler->filter('#navbar-menu'));
    }

    public function testANearlyExpiredTokenIsRotatedInsteadOfServed(): void
    {
        $user = $this->user('expiring');
        // Alive, but with only a few seconds left — scanning it would likely fail.
        $stale = new DigitalIdToken($user, 5);
        $this->em->persist($stale);
        $this->em->flush();

        $service = static::getContainer()->get(DigitalIdService::class);
        $issued = $service->getOrCreateActive($user);

        self::assertNotSame($stale->getToken(), $issued->getToken(), 'a near-dead token must not be handed out');
        self::assertGreaterThan(
            DigitalIdService::MIN_REMAINING_SECONDS,
            $issued->getExpiresAt()->getTimestamp() - time(),
        );
    }

    public function testAHealthyTokenIsReused(): void
    {
        $user = $this->user('stable');
        $fresh = new DigitalIdToken($user);
        $this->em->persist($fresh);
        $this->em->flush();

        $service = static::getContainer()->get(DigitalIdService::class);
        self::assertSame($fresh->getToken(), $service->getOrCreateActive($user)->getToken());
    }

    public function testCertificationScanRedirectsAnonymousToLogin(): void
    {
        $cert = new Certification('Cert under test');
        $cert->setIsActive(true)->setValidityPeriodDays(90);
        $this->em->persist($cert);
        $token = new CertificationToken($cert);
        $this->em->persist($token);
        $this->em->flush();

        // Anonymous: the firewall must bounce them through /login.
        $this->client->request('GET', '/certification-scan/verify/'.$token->getToken());

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testCertificationScanApprovesPendingApplicationWhenLoggedIn(): void
    {
        $user = $this->user('member');
        $cert = new Certification('Cert under test');
        $cert->setIsActive(true)->setValidityPeriodDays(90);
        $this->em->persist($cert);
        $token = new CertificationToken($cert);
        $this->em->persist($token);
        $application = new UserCertification($user, $cert);
        $application->setStatus(UserCertification::STATUS_PENDING);
        $this->em->persist($application);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/certification-scan/verify/'.$token->getToken());

        self::assertResponseIsSuccessful();

        $this->em->clear();
        $reloaded = $this->em->getRepository(UserCertification::class)->find($application->getId());
        self::assertSame(UserCertification::STATUS_APPROVED, $reloaded->getStatus());
        self::assertNotNull($reloaded->getDateCertified());
        self::assertNotNull($reloaded->getDateExpires());
    }
}
