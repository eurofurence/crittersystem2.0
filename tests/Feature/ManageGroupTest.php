<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ManageGroupTest extends DatabaseWebTestCase
{
    private const TOTP_SECRET = 'JBSWY3DPEHPK3PXP';


    /** @param string[] $privileges */
    private function makeUser(string $name, array $privileges, ?string $role = null): User
    {
        $group = new Group('Group '.$name, 'group-'.$name, $role);
        foreach ($privileges as $privilegeName) {
            $privilege = new Privilege($privilegeName);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName($name)
            ->setEmail($name.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function seededPrivilege(string $name): Privilege
    {
        $privilege = new Privilege($name);
        $this->em->persist($privilege);
        $this->em->flush();

        return $privilege;
    }

    private function enableTwoFactor(User $user): void
    {
        $user->setTotpSecret(self::TOTP_SECRET)->setTwoFactorEnabled(true);
        $this->em->flush();
    }

    /** Pass a fresh step-up by confirming a current TOTP code. */
    private function stepUp(): void
    {
        $totp = static::getContainer()->get(\App\TwoFactor\TotpService::class);
        $code = $totp->codeForCounter(self::TOTP_SECRET, intdiv(time(), 30));
        $this->client->request('POST', '/2fa/confirm', ['return' => '/manage/groups', 'code' => $code]);
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/manage/groups');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testUserWithoutViewPrivilegeIsForbidden(): void
    {
        $this->client->loginUser($this->makeUser('plain', ['news:view']));
        $this->client->request('GET', '/manage/groups');
        self::assertResponseStatusCodeSame(403);
    }

    /** rbac:group:view opens the list only; every mutation needs rbac:group:manage. */
    public function testViewerCanListButCannotCreate(): void
    {
        $this->client->loginUser($this->makeUser('viewer', ['rbac:group:view']));

        $this->client->request('GET', '/manage/groups');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/manage/groups/new');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * rbac:group:manage is a step-up permission: a holder without 2FA is sent to enrol before they
     * can mutate groups.
     */
    public function testManagerWithoutTwoFactorIsSentToEnrol(): void
    {
        $this->client->loginUser($this->makeUser('boss', ['rbac:group:view', 'rbac:group:manage']));

        $this->client->request('GET', '/manage/groups/new');
        self::assertResponseRedirects('/2fa/setup');
    }

    /** Creating a group needs a fresh step-up on top of rbac:group:manage. */
    public function testManagerCanCreateGroupWithPermissions(): void
    {
        $boss = $this->makeUser('boss', ['rbac:group:view', 'rbac:group:manage']);
        $this->enableTwoFactor($boss);
        $this->client->loginUser($boss);
        $this->seededPrivilege('news:manage');

        $this->stepUp();

        $crawler = $this->client->request('GET', '/manage/groups/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save')->form();
        $values = $form->getPhpValues();
        $values['group']['name'] = 'Press Team';
        $values['group']['role'] = 'ROLE_STAFF';
        $values['group']['privileges'] = ['news:manage'];
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseRedirects('/manage/groups');

        $group = $this->em->getRepository(Group::class)->findOneBy(['name' => 'Press Team']);
        self::assertNotNull($group);
        self::assertSame('ROLE_STAFF', $group->getRole());
        self::assertSame('press-team', $group->getSlug());
        $names = array_map(static fn (Privilege $p) => $p->getName(), $group->getPrivileges()->toArray());
        self::assertContains('news:manage', $names);
    }

    /**
     * The matrix answers "who grants this permission", which the per-group screens cannot: reading
     * it out of a dozen edit pages is how a stray grant goes unnoticed. The header holds one column
     * per group plus the frozen permission column.
     */
    public function testTheMatrixShowsEveryGroupAgainstEveryPermission(): void
    {
        $viewer = $this->makeUser('viewer', ['rbac:group:view']);
        $this->makeUser('editor', ['news:manage']);
        $this->client->loginUser($viewer);

        $crawler = $this->client->request('GET', '/manage/groups/matrix');
        self::assertResponseIsSuccessful();

        self::assertCount(3, $crawler->filter('.perm-matrix thead th'));

        $row = $crawler->filter('tr:has(code:contains("news:manage"))');
        self::assertCount(1, $row->filter('.perm-matrix-cell.is-granted'), 'exactly the group holding it is ticked');
        self::assertGreaterThan(0, $crawler->filter('.perm-matrix-category')->count(), 'permissions are grouped by category');
    }

    /**
     * A permission granted to a group but missing from the catalogue still has to appear: a grant
     * nobody can see is a grant nobody reviews.
     */
    public function testTheMatrixListsAGrantTheCatalogueDoesNotKnow(): void
    {
        $viewer = $this->makeUser('viewer', ['rbac:group:view']);
        $this->makeUser('legacy', ['legacy:leftover']);
        $this->client->loginUser($viewer);

        $crawler = $this->client->request('GET', '/manage/groups/matrix');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('legacy:leftover', $crawler->filter('.perm-matrix')->text());
    }

    /**
     * The picker narrows the columns to the groups asked for. Everything else stays: the rows are
     * still every permission, so a comparison of two groups is not also a filtered list.
     */
    public function testTheMatrixDrawsOnlyThePickedGroups(): void
    {
        $viewer = $this->makeUser('viewer', ['rbac:group:view']);
        $editor = $this->makeUser('editor', ['news:manage']);
        $editorGroup = $editor->getGroups()->first();
        $this->client->loginUser($viewer);

        $crawler = $this->client->request('GET', '/manage/groups/matrix?groups%5B0%5D='.$editorGroup->getUuid());

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('.perm-matrix thead th'), 'one group column plus the permission column');
        self::assertStringContainsString($editorGroup->getName(), $crawler->filter('.perm-matrix-group')->text());
        self::assertCount(2, $crawler->filter('input[name="groups[]"]'), 'the picker still offers every group');
        self::assertCount(1, $crawler->filter('input[name="groups[]"][checked]'));
    }

    /** No selection is the default, and unticking everything must not leave a matrix with no columns. */
    public function testTheMatrixFallsBackToEveryGroupWhenNothingIsPicked(): void
    {
        $this->makeUser('editor', ['news:manage']);
        $this->client->loginUser($this->makeUser('viewer', ['rbac:group:view']));

        $bare = $this->client->request('GET', '/manage/groups/matrix');
        self::assertCount(3, $bare->filter('.perm-matrix thead th'));

        $unpicked = $this->client->request('GET', '/manage/groups/matrix?groups%5B0%5D=');
        self::assertCount(3, $unpicked->filter('.perm-matrix thead th'));
        self::assertCount(2, $unpicked->filter('input[name="groups[]"][checked]'));
    }

    public function testTheMatrixNeedsTheViewPrivilege(): void
    {
        $this->client->loginUser($this->makeUser('plain', ['shift:view']));
        $this->client->request('GET', '/manage/groups/matrix');

        self::assertResponseStatusCodeSame(403);
    }

    /** The button is on the group list, and only for somebody allowed through the door. */
    public function testTheGroupListLinksToTheMatrix(): void
    {
        $this->client->loginUser($this->makeUser('viewer', ['rbac:group:view']));
        $crawler = $this->client->request('GET', '/manage/groups');

        self::assertCount(1, $crawler->filter('a[href="/manage/groups/matrix"]'));
    }
}
