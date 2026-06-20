<?php

namespace App\Tests\Feature;

use App\Entity\Certification;
use App\Entity\CertificationToken;
use App\Entity\DigitalIdToken;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Entity\UserCertification;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class QrFeaturesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        try {
            $this->em->getConnection()->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Database not available: '.$e->getMessage());
        }

        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropDatabase();
        $schemaTool->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    private function user(string $name): User
    {
        $group = new Group(20, 'g'.$name, 'g-'.$name);
        $group->addPrivilege($privilege = new Privilege('news'));
        $this->em->persist($privilege);
        $this->em->persist($group);

        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $user->addGroup($group);
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
