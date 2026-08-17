<?php

namespace App\Tests\Feature;

use App\Entity\AuditExport;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Storage\ExportStorage;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminAuditTest extends DatabaseWebTestCase
{
    private const TOTP_SECRET = 'JBSWY3DPEHPK3PXP';


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
        $this->client->request('POST', '/2fa/confirm', ['return' => '/manage/audit', 'code' => $code]);
    }

    public function testNonAdminCannotReachAudit(): void
    {
        $this->client->loginUser($this->makeUser('vol', null, ['shift:view']));
        $this->client->request('GET', '/manage/audit');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * The audit log is critical data: reading it needs a fresh 2FA step-up, and without one
     * the admin is redirected to the confirm challenge. A stored, unexpired export offers a
     * download link; once the archive is gone from storage the row must stop offering a
     * download it cannot serve.
     *
     * The page is rendered a second time only after an export row exists, because rendering
     * it against an empty list never enters the export table body.
     */
    public function testGlobalAdminCanViewAndExport(): void
    {
        $this->client->loginUser($this->makeUser('boss', 'ROLE_ADMIN', ['global:admin']));

        $this->client->request('GET', '/manage/audit');
        self::assertResponseRedirects('/2fa/confirm?return=/manage/audit');

        $this->stepUp();

        $crawler = $this->client->request('GET', '/manage/audit');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/manage/audit/export', [
            '_token' => $token,
            'from' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d'),
            'to' => (new \DateTimeImmutable('+1 day'))->format('Y-m-d'),
        ]);
        self::assertResponseRedirects('/manage/audit');

        $exports = $this->em->getRepository(AuditExport::class)->findAll();
        self::assertCount(1, $exports);
        $storage = static::getContainer()->get(ExportStorage::class);
        self::assertTrue($storage->exists($exports[0]->getStorageKey()));

        $crawler = $this->client->request('GET', '/manage/audit');
        self::assertResponseIsSuccessful();
        self::assertSame(
            1,
            $crawler->filter('a[href="/manage/audit/download/'.$exports[0]->getUuid().'"]')->count(),
            'a stored, unexpired export offers a download link',
        );

        $storage->delete($exports[0]->getStorageKey());
        $crawler = $this->client->request('GET', '/manage/audit');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('a[href^="/manage/audit/download/"]')->count());
    }
}
