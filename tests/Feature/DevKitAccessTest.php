<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Tests\Controller\SecurityGateTest;
use App\Tests\DatabaseWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The UI kit pages are developer tools, so they are gated twice: they are not routable in
 * prod at all (App\Dev\ is excluded from the container there - see config/services.yaml),
 * and they require the `global:admin` super-privilege where they do exist.
 */
final class DevKitAccessTest extends DatabaseWebTestCase
{
    /** @return iterable<string, array{string}> */
    public static function kitUrls(): iterable
    {
        yield from SecurityGateTest::kitUrls();
    }

    private function login(string $privilege): void
    {
        $group = new Group('Kit '.$privilege, 'kit-'.bin2hex(random_bytes(3)), 'ROLE_USER');
        $priv = new Privilege($privilege);
        $this->em->persist($priv);
        $group->addPrivilege($priv);
        $this->em->persist($group);

        $email = 'kit-'.bin2hex(random_bytes(4)).'@example.com';
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('kit-user')->setEmail($email)->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['email' => $email]));
    }

    #[DataProvider('kitUrls')]
    public function testAdminCanOpenEveryKitPage(string $url): void
    {
        $this->login('global:admin');
        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
    }

    #[DataProvider('kitUrls')]
    public function testNonAdminIsForbiddenFromEveryKitPage(string $url): void
    {
        $this->login('shift:manage');
        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(403);
    }
}
