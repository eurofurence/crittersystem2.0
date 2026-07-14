<?php

namespace App\Tests\Feature;

use App\Entity\AvailabilityRange;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Availability self-service page and submission. */
final class AvailabilityPageTest extends DatabaseWebTestCase
{
    private function login(): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('vol')->setEmail('vol@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        return $user;
    }

    public function testPageRendersGridForLoggedInUser(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/availability');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.avail-day-body')->count());
    }

    public function testSubmitSavesAvailability(): void
    {
        $user = $this->login();
        $crawler = $this->client->request('GET', '/availability');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/availability', [
            '_token' => $token,
            'comment' => 'Call me',
            'ranges' => json_encode([
                ['start' => '2026-06-01T10:00:00', 'end' => '2026-06-01T14:00:00', 'value' => 'preferred'],
            ]),
        ]);

        self::assertResponseRedirects('/availability');
        $ranges = $this->em->getRepository(AvailabilityRange::class)->findForUser($user);
        self::assertCount(1, $ranges);
        self::assertSame('preferred', $ranges[0]->getValue()->value);
    }
}
