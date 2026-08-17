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

    /** The verify page is public: a scanner with no session must still be able to read it. */
    public function testDigitalIdVerifyIsPublicAndShowsTheUser(): void
    {
        $user = $this->user('scanme');
        $token = new DigitalIdToken($user);
        $this->em->persist($token);
        $this->em->flush();

        $this->client->request('GET', '/digital-id/verify/'.$token->getToken());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'scanme');
    }

    public function testDigitalIdVerifyRejectsUnknownToken(): void
    {
        $this->client->request('GET', '/digital-id/verify/'.str_repeat('0', 64));
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The card refreshes through the live region, never a <meta http-equiv="refresh">: a meta
     * refresh survives Turbo navigation and drags the user back to this page long after they left
     * it. The region is located by its own url value because the layout carries live regions too.
     *
     * It declares a moment, not an interval. The card re-renders once when its token is about to
     * lapse and the fresh fragment then declares its own next moment; a repeating interval keeps
     * the first token's remaining life forever, so a card opened on a nearly expired token would
     * re-render every few seconds for as long as it stayed open.
     */
    public function testDigitalIdRotatesViaStimulusAndNotAMetaRefresh(): void
    {
        $user = $this->user('carrier');
        $this->em->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/digital-id');
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('meta[http-equiv="refresh"]'), 'the page must not use a meta refresh');

        $region = $crawler->filter('[data-live-stream-url-value="/digital-id/card"]');
        self::assertCount(1, $region, 'the QR card re-renders itself before its token expires');
        self::assertSame('live-stream', $region->attr('data-controller'));

        $declared = $crawler->filter('[data-next-transition]');
        self::assertCount(1, $declared, 'the card must declare when it next changes');
        self::assertGreaterThan(
            time(),
            strtotime((string) $declared->attr('data-next-transition')),
            'the declared moment must be in the future',
        );
    }

    /** The card endpoint answers with a fragment: the layout must not come along with it. */
    public function testDigitalIdCardEndpointReturnsTheQrOnItsOwn(): void
    {
        $user = $this->user('poller');
        $this->em->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/digital-id/card');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('img[alt="Digital ID QR code"]'));
        self::assertCount(0, $crawler->filter('#navbar-menu'));
    }

    /**
     * A token that is still alive but has only seconds left is rotated rather than handed out:
     * scanning it would most likely fail before the reader got there.
     */
    public function testANearlyExpiredTokenIsRotatedInsteadOfServed(): void
    {
        $user = $this->user('expiring');
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

    /** A scan link is not public: an anonymous scanner is bounced through /login by the firewall. */
    public function testCertificationScanRedirectsAnonymousToLogin(): void
    {
        $cert = new Certification('Cert under test');
        $cert->setIsActive(true)->setValidityPeriodDays(90);
        $this->em->persist($cert);
        $token = new CertificationToken($cert);
        $this->em->persist($token);
        $this->em->flush();

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
