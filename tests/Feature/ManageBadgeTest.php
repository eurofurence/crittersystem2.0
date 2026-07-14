<?php

namespace App\Tests\Feature;

use App\Entity\Badge;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ManageBadgeTest extends DatabaseWebTestCase
{
    private function makeUser(string $name, array $privileges): User
    {
        $group = new Group('G'.$name, 'g-'.$name);
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
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testWithoutPrivilegeForbidden(): void
    {
        $this->client->loginUser($this->makeUser('plain', ['shift:view']));
        $this->client->request('GET', '/manage/badges');
        self::assertResponseStatusCodeSame(403);
    }

    public function testManagerCanCreateBadge(): void
    {
        $this->client->loginUser($this->makeUser('boss', ['badge:manage']));

        $crawler = $this->client->request('GET', '/manage/badges/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Save')->form();
        $values = $form->getPhpValues();
        $values['badge']['name'] = 'Photographer';
        $values['badge']['type'] = Badge::TYPE_STANDARD;
        $values['badge']['color'] = 'pink';
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects('/manage/badges');

        $badge = $this->em->getRepository(Badge::class)->findOneBy(['name' => 'Photographer']);
        self::assertNotNull($badge);
        self::assertSame('photographer', $badge->getSlug());
    }

    public function testBatchAssignAddsAndRemovesBadge(): void
    {
        $assigner = $this->makeUser('assigner', ['badge:assign']);
        $this->client->loginUser($assigner);

        $badge = new Badge('Security', 'security', Badge::TYPE_STANDARD);
        $this->em->persist($badge);
        $target = $this->makeUser('target', ['shift:view']);
        $this->em->flush();

        $this->client->request('POST', '/manage/badges/assign', [
            'badge' => $badge->getUuid(), 'action' => 'add', 'users' => [$target->getId()],
        ]);
        self::assertResponseRedirects();
        $this->em->clear();
        self::assertTrue($this->em->getRepository(User::class)->find($target->getId())->hasBadge(
            $this->em->getRepository(Badge::class)->find($badge->getId()),
        ));
    }
}
