<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use App\TwoFactor\TotpService;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class TwoFactorSetupTest extends DatabaseWebTestCase
{
    private function makeUser(): User
    {
        $group = new Group('Volunteers', 'volunteers', null);
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('twofa')->setEmail('twofa@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testEnablingTwoFactorRedirectsToAndShowsRecoveryCodes(): void
    {
        $user = $this->makeUser();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/2fa/setup');
        self::assertResponseIsSuccessful();
        $secret = $crawler->filter('code')->first()->text();

        /** @var TotpService $totp */
        $totp = static::getContainer()->get(TotpService::class);
        $code = $totp->codeForCounter($secret, intdiv(time(), 30));

        $this->client->request('POST', '/2fa/setup', ['code' => $code]);
        self::assertResponseRedirects('/2fa/recovery-codes');

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertCount(8, $crawler->filter('ul li code'), 'all eight recovery codes are displayed');

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        self::assertTrue($reloaded->isTwoFactorEnabled());
    }

    public function testRecoveryCodesAreShownOnlyOnce(): void
    {
        $user = $this->makeUser();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/2fa/setup');
        $secret = $crawler->filter('code')->first()->text();
        /** @var TotpService $totp */
        $totp = static::getContainer()->get(TotpService::class);
        $this->client->request('POST', '/2fa/setup', ['code' => $totp->codeForCounter($secret, intdiv(time(), 30))]);
        $this->client->followRedirect();

        // A refresh (or any later visit) must not re-display the one-time codes.
        $this->client->request('GET', '/2fa/recovery-codes');
        self::assertResponseRedirects('/2fa');
    }
}
