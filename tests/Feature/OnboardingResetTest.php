<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Admin-triggered re-run of onboarding.
 *
 * The defining property: requesting a reset must NOT disturb a signed-in user.
 * The onboarding gate reads the completed flag on every request and the user
 * provider reloads the user from the database, so applying the reset eagerly
 * would drop live sessions into the wizard. It is applied at next sign-in instead.
 */
final class OnboardingResetTest extends DatabaseWebTestCase
{
    private function user(string $role = 'ROLE_USER', array $privileges = []): User
    {
        $suffix = bin2hex(random_bytes(4));
        $group = new Group('Grp '.$suffix, 'grp-'.$suffix, $role);
        foreach ($privileges as $name) {
            $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => $name]) ?? new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $user = new User();
        $user->setName('u-'.$suffix)->setEmail('u-'.$suffix.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function admin(): User
    {
        return $this->user('ROLE_ADMIN', ['user:view']);
    }

    /**
     * Re-read a user from the database. Doctrine's EntityManager is reset between
     * requests, which detaches everything loaded before them, so refresh() would
     * throw rather than return the post-request state.
     */
    private function reload(User $user): User
    {
        $id = $user->getId();
        $this->em->clear();

        return $this->em->getRepository(User::class)->find($id);
    }

    /**
     * Submit the real form from /manage/users, rather than minting a CSRF token -
     * that needs a session, and this also exercises the page the admin actually uses.
     */
    private function submitAction(string $action): void
    {
        $crawler = $this->client->request('GET', '/manage/users');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action="'.$action.'"]');
        self::assertGreaterThan(0, $form->count(), 'Expected an action form at '.$action);

        $this->client->submit($form->form());
    }

    /**
     * The reset is queued only: the completion flag stays set until the volunteer signs in again,
     * so nothing changes under a session that is already open.
     */
    public function testAdminCanQueueAResetForOneUser(): void
    {
        $volunteer = $this->user();
        $this->client->loginUser($this->admin());

        $this->submitAction('/manage/users/'.$volunteer->getUuid().'/reset-onboarding');

        self::assertResponseRedirects('/manage/users');
        $volunteer = $this->reload($volunteer);
        self::assertTrue($volunteer->isOnboardingResetPending());
        self::assertTrue($volunteer->isOnboardingCompleted());
    }

    /**
     * A queued reset leaves a signed-in user working normally: they are neither redirected to the
     * wizard nor signed out.
     */
    public function testQueuedResetDoesNotDisturbASignedInUser(): void
    {
        $volunteer = $this->user();
        $volunteer->requestOnboardingReset();
        $this->em->flush();

        $this->client->loginUser($volunteer);
        $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('/onboarding', (string) $this->client->getResponse()->headers->get('Location', ''));
    }

    public function testResetIsAppliedOnNextSignIn(): void
    {
        $volunteer = $this->user();
        $volunteer->requestOnboardingReset();
        $this->em->flush();

        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', [
            '_username' => $volunteer->getName(),
            '_password' => 'secret123',
        ]);

        $volunteer = $this->reload($volunteer);
        self::assertFalse($volunteer->isOnboardingCompleted(), 'Signing in must apply the queued reset');
        self::assertFalse($volunteer->isOnboardingResetPending(), 'Applying the reset must clear the request');
    }

    /** Once applied, the existing gate does the rest. */
    public function testUserIsSentToOnboardingAfterTheResetIsApplied(): void
    {
        $volunteer = $this->user();
        $volunteer->resetOnboarding();
        $this->em->flush();

        $this->client->loginUser($volunteer);
        $this->client->request('GET', '/dashboard');

        self::assertResponseRedirects('/onboarding');
    }

    public function testQueuedResetIsNotConsumedByAnApiKeyRequest(): void
    {
        $volunteer = $this->user();
        $volunteer->requestOnboardingReset();
        $this->em->flush();

        $this->client->request('GET', '/api/v0-beta/users/self', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$volunteer->getApiKey(),
        ]);

        self::assertResponseIsSuccessful();
        $volunteer = $this->reload($volunteer);
        self::assertTrue(
            $volunteer->isOnboardingResetPending(),
            'A stateless API call must not consume the reset - the user would never see the wizard',
        );
    }

    public function testQueueCanBeCancelled(): void
    {
        $volunteer = $this->user();
        $volunteer->requestOnboardingReset();
        $this->em->flush();

        $this->client->loginUser($this->admin());
        $this->submitAction('/manage/users/'.$volunteer->getUuid().'/reset-onboarding');

        $volunteer = $this->reload($volunteer);
        self::assertFalse($volunteer->isOnboardingResetPending());
    }

    public function testNonAdminCannotQueueAReset(): void
    {
        $volunteer = $this->user();
        $this->client->loginUser($this->user('ROLE_STAFF', ['user:view', 'user:edit']));

        $this->client->request('POST', '/manage/users/'.$volunteer->getUuid().'/reset-onboarding');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $volunteer = $this->reload($volunteer);
        self::assertFalse($volunteer->isOnboardingResetPending());
    }

    public function testRequestWithoutCsrfTokenDoesNothing(): void
    {
        $volunteer = $this->user();
        $this->client->loginUser($this->admin());

        $this->client->request('POST', '/manage/users/'.$volunteer->getUuid().'/reset-onboarding');

        $volunteer = $this->reload($volunteer);
        self::assertFalse($volunteer->isOnboardingResetPending());
    }

    public function testBulkResetFlagsOnboardedUsersOnly(): void
    {
        $onboarded = $this->user();

        $notYet = $this->user();
        $notYet->resetOnboarding();
        $this->em->flush();

        $this->client->loginUser($this->admin());
        $this->submitAction('/manage/users/reset-onboarding-all');

        $notYetId = $notYet->getId();
        $onboarded = $this->reload($onboarded);
        $notYet = $this->em->getRepository(User::class)->find($notYetId);
        self::assertTrue($onboarded->isOnboardingResetPending());
        self::assertFalse(
            $notYet->isOnboardingResetPending(),
            'Someone mid-onboarding is already going to see the wizard; flagging them misreports the count',
        );
    }
}
