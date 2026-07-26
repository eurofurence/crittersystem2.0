<?php

namespace App\Tests\Feature;

use App\Entity\Location;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;

/**
 * Protects the location detail page against dropping first-class location fields: the phone number
 * is editable and importable, so it must be shown to viewers, not silently omitted.
 */
final class LocationShowTest extends DatabaseWebTestCase
{
    private function viewer(): User
    {
        $user = new User();
        $user->setName('viewer')->setEmail('viewer@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $user->completeOnboarding();
        $this->em->persist($user);

        return $user;
    }

    public function testDetailPageShowsThePhoneNumber(): void
    {
        $viewer = $this->viewer();
        $location = new Location('Info Booth');
        $location->setAlias('info-booth')->setPhone('+49 30 123456');
        $this->em->persist($location);
        $this->em->flush();

        $this->client->loginUser($viewer);
        $crawler = $this->client->request('GET', '/locations/'.$location->getUuid());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.page-body', '+49 30 123456');
        self::assertSame(
            1,
            $crawler->filter('a[href="tel:+4930123456"]')->count(),
            'the phone number is rendered as a tel: link with spaces stripped',
        );
    }
}
