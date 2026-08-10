<?php

namespace App\Tests\Feature;

use App\Entity\State;
use App\Entity\User;
use App\Service\EventConfigStore;
use App\Tests\DatabaseWebTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The check-in banner on the shift list.
 *
 * A volunteer who has not been checked in is refused every main-event shift, and the refusal alone
 * does not say what to do about it. The banner carries the admin-authored text naming the desk they
 * have to visit, in the language they are reading the site in.
 */
final class CheckInBannerTest extends DatabaseWebTestCase
{
    private ShiftScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = new ShiftScenario($this->em, static::getContainer()->get(UserPasswordHasherInterface::class));
    }

    private function configure(string $english, string $german): void
    {
        $config = static::getContainer()->get(EventConfigStore::class);
        $config->set(EventConfigStore::KEY_CHECKIN_MESSAGE_EN, $english);
        $config->set(EventConfigStore::KEY_CHECKIN_MESSAGE_DE, $german);
        $config->flush();
    }

    private function arrive(User $user): void
    {
        $state = new State($user);
        $state->setArrived(true);
        $user->setState($state);
        $this->em->persist($state);
        $this->em->flush();
    }

    private function body(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    public function testTheBannerIsShownToAVolunteerWhoHasNotCheckedIn(): void
    {
        $this->configure('Go to the info desk in Hall 5.', 'Gehe zum Info-Desk in Halle 5.');
        $this->client->loginUser($this->scenario->user(memberOf: $this->scenario->type));

        $this->client->request('GET', '/shifts');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Go to the info desk in Hall 5.', $this->body());
    }

    public function testTheBannerIsHiddenOnceTheVolunteerHasCheckedIn(): void
    {
        $this->configure('Go to the info desk in Hall 5.', 'Gehe zum Info-Desk in Halle 5.');
        $user = $this->scenario->user(memberOf: $this->scenario->type);
        $this->arrive($user);
        $this->client->loginUser($user);

        $this->client->request('GET', '/shifts');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Go to the info desk in Hall 5.', $this->body());
    }

    public function testAGermanReaderGetsTheGermanText(): void
    {
        $this->configure('Go to the info desk in Hall 5.', 'Gehe zum Info-Desk in Halle 5.');
        $this->client->loginUser($this->scenario->user(memberOf: $this->scenario->type));

        $this->client->request('GET', '/shifts?_locale=de');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Gehe zum Info-Desk in Halle 5.', $this->body());
    }

    /**
     * A volunteer who cannot apply must always be told where to go, so an unfilled German field
     * falls back to the English text rather than rendering an empty banner.
     */
    public function testABlankGermanTextFallsBackToEnglish(): void
    {
        $this->configure('Go to the info desk in Hall 5.', '');
        $this->client->loginUser($this->scenario->user(memberOf: $this->scenario->type));

        $this->client->request('GET', '/shifts?_locale=de');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Go to the info desk in Hall 5.', $this->body());
    }

    /** Klingon is offered by the UI but has no field of its own. */
    public function testAnUnconfiguredLocaleFallsBackToEnglish(): void
    {
        $this->configure('Go to the info desk in Hall 5.', 'Gehe zum Info-Desk in Halle 5.');
        $this->client->loginUser($this->scenario->user(memberOf: $this->scenario->type));

        $this->client->request('GET', '/shifts?_locale=tlh');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Go to the info desk in Hall 5.', $this->body());
    }

    /**
     * The text is admin-authored free text rendered inside an alert; it must be escaped, not
     * injected as markup.
     */
    public function testTheMessageIsEscaped(): void
    {
        $this->configure('Desk <script>alert(1)</script>', '');
        $this->client->loginUser($this->scenario->user(memberOf: $this->scenario->type));

        $this->client->request('GET', '/shifts');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('<script>alert(1)</script>', $this->body());
        self::assertStringContainsString('&lt;script&gt;', $this->body());
    }

    public function testTheDefaultMessageIsUsedWhenNothingIsConfigured(): void
    {
        $this->client->loginUser($this->scenario->user(memberOf: $this->scenario->type));

        $this->client->request('GET', '/shifts');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(EventConfigStore::DEFAULT_CHECKIN_MESSAGE_EN, $this->body());
    }
}
