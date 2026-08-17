<?php

namespace App\Tests\Feature;

use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * /home - the fixed "about the system" mural. Its text is intentionally not
 * translated, so the assertions look for the literal reference copy.
 */
final class HomeTest extends DatabaseWebTestCase
{
    private function login(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('visitor')->setEmail('visitor@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    public function testItShowsTheSystemIntroductionAndCredits(): void
    {
        $this->login();

        $crawler = $this->client->request('GET', '/home');

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('body')->text();
        self::assertStringContainsString('Volunteer Management System', $text);
        self::assertStringContainsString('Critter System', $text);
        self::assertStringContainsString('Credits', $text);
    }

    /**
     * The page states the deployed version. The assertion is on a non-empty code value rather than
     * a literal, because the resolver's answer depends on how the checkout was built.
     */
    public function testItShowsTheDeployedVersion(): void
    {
        $this->login();

        $crawler = $this->client->request('GET', '/home');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Version:', $crawler->filter('body')->text());
        self::assertNotSame('', trim($crawler->filter('code')->first()->text()));
    }

    public function testTheSymfonyScaffoldContentIsGone(): void
    {
        $this->login();

        $crawler = $this->client->request('GET', '/home');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Hello HomeController', $crawler->filter('body')->text());
    }
}
