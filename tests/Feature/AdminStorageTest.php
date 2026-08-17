<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;

/**
 * The storage diagnostics page is admin-only (global:admin) and its actions are
 * CSRF-guarded. Uploads and exports round-trip through the local test backends,
 * so the app-storage test must report every step as passing; the backup form
 * rejects an empty bucket without touching the network.
 */
final class AdminStorageTest extends DatabaseWebTestCase
{
    private function user(?string $role, string ...$privileges): User
    {
        $suffix = bin2hex(random_bytes(3));
        $group = new Group('G'.$suffix, 'g-'.$suffix, $role);
        foreach ($privileges as $privilege) {
            $p = new Privilege($privilege);
            $this->em->persist($p);
            $group->addPrivilege($p);
        }
        $this->em->persist($group);

        $user = new User();
        $user->setName('u'.$suffix)->setEmail($suffix.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testNonAdminIsDenied(): void
    {
        $this->client->loginUser($this->user('ROLE_USER', 'global:dashboard'));
        $this->client->request('GET', '/admin/storage');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSeesThePage(): void
    {
        $this->client->loginUser($this->user('ROLE_ADMIN', 'global:admin'));
        $this->client->request('GET', '/admin/storage');
        self::assertResponseIsSuccessful();
    }

    /** Every round-trip step passes on the local backend, and no step reports a failure. */
    public function testAppStorageRoundTripSucceeds(): void
    {
        $this->client->loginUser($this->user('ROLE_ADMIN', 'global:admin'));
        $crawler = $this->client->request('GET', '/admin/storage');

        $this->client->submit($crawler->selectButton('Run storage test')->form());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Delete file');
        self::assertStringNotContainsString('Failed', (string) $this->client->getResponse()->getContent());
    }

    /**
     * An invalid submission re-renders the form with a 422 rather than redirecting, because
     * Turbo silently discards a rendered 200 answer to a form post, and no backup is attempted.
     */
    public function testBackupFormRejectsEmptyBucket(): void
    {
        $this->client->loginUser($this->user('ROLE_ADMIN', 'global:admin'));
        $crawler = $this->client->request('GET', '/admin/storage');

        $this->client->submit($crawler->selectButton('Test backup')->form([
            'backup_test[bucket]' => '',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('form[name="backup_test"]');
    }
}
