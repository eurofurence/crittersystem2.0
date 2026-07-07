<?php

namespace App\Tests\Feature;

use App\Entity\BannedIdentity;
use App\Entity\ErasureRequest;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Settings;
use App\Entity\User;
use App\Gdpr\BanChecker;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GdprFlowTest extends WebTestCase
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

    private function makeUser(string $name, ?string $role, array $privileges = []): User
    {
        $group = new Group('G'.$name, 'g-'.$name, $role);
        foreach ($privileges as $p) {
            $priv = new Privilege($p);
            $this->em->persist($priv);
            $group->addPrivilege($priv);
        }
        $this->em->persist($group);

        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $user->setSettings(new Settings($user));
        $user->addGroup($group);
        $user->completeOnboarding();
        if ($role === 'ROLE_ADMIN') { $user->setTotpSecret('JBSWY3DPEHPK3PXP')->setTwoFactorEnabled(true); }
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testErasureLinkDeletesAndBans(): void
    {
        $user = $this->makeUser('victim', null);
        $email = $user->getEmail();
        $userId = $user->getId();
        $request = new ErasureRequest($user, 'erase-flow-1');
        $this->em->persist($request);
        $this->em->flush();

        $this->client->request('GET', '/erase/erase-flow-1');
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/erase/erase-flow-1');
        self::assertResponseIsSuccessful();

        $this->em->clear();
        self::assertNull($this->em->getRepository(User::class)->find($userId));
        self::assertTrue(static::getContainer()->get(BanChecker::class)->isEmailBanned($email));
    }

    public function testAppealMarksBanAndAdminCanLift(): void
    {
        /** @var BanChecker $bans */
        $bans = static::getContainer()->get(BanChecker::class);
        $hash = $bans->hashEmail('banned@example.com');
        $this->em->persist(new BannedIdentity(BannedIdentity::TYPE_EMAIL, $hash));
        $this->em->flush();

        // Public appeal (no login).
        $this->client->request('POST', '/appeal', ['email' => 'banned@example.com']);
        self::assertResponseIsSuccessful();
        $this->em->clear();
        $ban = $this->em->getRepository(BannedIdentity::class)->findOneBy(['hash' => $hash]);
        self::assertTrue($ban->hasAppeal());

        // Admin lifts the ban.
        $this->client->loginUser($this->makeUser('root', 'ROLE_ADMIN', ['global:admin']));
        $crawler = $this->client->request('GET', '/manage/bans');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/manage/bans/'.$ban->getId().'/lift', ['_token' => $token]);
        self::assertResponseRedirects('/manage/bans');

        $this->em->clear();
        self::assertNull($this->em->getRepository(BannedIdentity::class)->findOneBy(['hash' => $hash]));
    }

    public function testBannedEmailCannotBeReInvited(): void
    {
        $bans = static::getContainer()->get(BanChecker::class);
        $this->em->persist(new BannedIdentity(BannedIdentity::TYPE_EMAIL, $bans->hashEmail('return@example.com')));
        $this->em->flush();

        $this->client->loginUser($this->makeUser('admin2', 'ROLE_ADMIN', ['global:admin']));
        $crawler = $this->client->request('GET', '/manage/users/invite');
        $form = $crawler->selectButton('Send invitation')->form();
        $values = $form->getPhpValues();
        $values['user_invite']['username'] = 'returnee';
        $values['user_invite']['email'] = 'return@example.com';
        $this->client->request('POST', $form->getUri(), $values);

        // No redirect (stays on form) and no user created.
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['name' => 'returnee']));
    }
}
