<?php

namespace App\Tests\Feature;

use App\Entity\Settings;
use App\Entity\User;
use App\Entity\UserConsent;
use App\Tests\DatabaseWebTestCase;

/**
 * The self-service contact-visibility screen: a volunteer may change who sees
 * their details (GDPR Art. 7(3) withdrawal), but may not withdraw the last
 * channel and leave themselves unreachable.
 */
final class ContactVisibilityEditTest extends DatabaseWebTestCase
{
    private function user(): User
    {
        $user = new User();
        $user->setName('vol')->setEmail('vol@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $user->setSettings(new Settings($user));
        $user->completeOnboarding();
        $consent = (new UserConsent($user))->setEmailVisible(true);
        $user->setConsent($consent);
        $this->em->persist($user);
        $this->em->persist($consent);
        $this->em->flush();

        return $user;
    }

    private function token(): string
    {
        $this->client->request('GET', '/profile/privacy');
        self::assertResponseIsSuccessful();

        return $this->client->getCrawler()->filter('form[action$="/contact-visibility"] input[name="_token"]')->attr('value');
    }

    public function testChangingVisibleFlagsIsSavedWithProvenance(): void
    {
        $user = $this->user();
        $this->client->loginUser($user);

        $this->client->request('POST', '/profile/privacy/contact-visibility', [
            '_token' => $this->token(), 'show_email' => '1', 'show_name' => '1',
        ]);
        self::assertResponseRedirects('/profile/privacy');

        $this->em->clear();
        $consent = $this->em->getRepository(User::class)->find($user->getId())->getConsent();
        self::assertTrue($consent->isEmailVisible());
        self::assertTrue($consent->isFullNameVisible());
        self::assertNotNull($consent->getVisibilityConsentedAt());
    }

    /**
     * A volunteer may not withdraw their last contact channel. This user has no phone and no
     * telegram, so withdrawing everything would leave them unreachable and is refused.
     */
    public function testLastChannelCannotBeWithdrawn(): void
    {
        $user = $this->user();
        $this->client->loginUser($user);

        $this->client->request('POST', '/profile/privacy/contact-visibility', [
            '_token' => $this->token(), 'show_name' => '1',
        ]);
        self::assertResponseRedirects('/profile/privacy');

        $this->em->clear();
        $consent = $this->em->getRepository(User::class)->find($user->getId())->getConsent();
        self::assertTrue($consent->isEmailVisible(), 'email visibility is preserved, not silently dropped');
    }
}
