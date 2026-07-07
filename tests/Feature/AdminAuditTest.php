<?php

namespace App\Tests\Feature;

use App\Entity\AuditExport;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminAuditTest extends WebTestCase
{
    private const TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

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

    private function makeUser(string $name, ?string $role, array $privileges): User
    {
        $group = new Group('G'.$name, 'g-'.$name, $role);
        foreach ($privileges as $p) {
            $priv = new Privilege($p);
            $this->em->persist($priv);
            $group->addPrivilege($priv);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        if ($role === 'ROLE_ADMIN') {
            $user->setTotpSecret(self::TOTP_SECRET)->setTwoFactorEnabled(true);
        }
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** Pass a fresh step-up by confirming a current TOTP code. */
    private function stepUp(): void
    {
        $totp = static::getContainer()->get(\App\TwoFactor\TotpService::class);
        $code = $totp->codeForCounter(self::TOTP_SECRET, intdiv(time(), 30));
        $this->client->request('POST', '/2fa/confirm', ['return' => '/admin/audit', 'code' => $code]);
    }

    public function testNonAdminCannotReachAudit(): void
    {
        $this->client->loginUser($this->makeUser('vol', null, ['shift:view']));
        $this->client->request('GET', '/admin/audit');
        self::assertResponseStatusCodeSame(403);
    }

    public function testGlobalAdminCanViewAndExport(): void
    {
        $this->client->loginUser($this->makeUser('boss', 'ROLE_ADMIN', ['global:admin']));

        $crawler = $this->client->request('GET', '/admin/audit');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');

        // Exporting requires a fresh step-up authentication.
        $this->stepUp();

        $this->client->request('POST', '/admin/audit/export', [
            '_token' => $token,
            'from' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d'),
            'to' => (new \DateTimeImmutable('+1 day'))->format('Y-m-d'),
        ]);
        self::assertResponseRedirects('/admin/audit');

        $exports = $this->em->getRepository(AuditExport::class)->findAll();
        self::assertCount(1, $exports);
        self::assertTrue($exports[0]->fileExists());
        @unlink($exports[0]->getFilePath());
    }
}
