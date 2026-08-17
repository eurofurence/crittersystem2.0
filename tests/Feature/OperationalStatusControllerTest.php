<?php

namespace App\Tests\Feature;

use App\Entity\User;
use App\Service\OperationalStatusService;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class OperationalStatusControllerTest extends DatabaseWebTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setName('statususer')->setEmail('status@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $this->user->setPassword($hasher->hashPassword($this->user, 'secret123'));
        $this->user->completeOnboarding();
        $this->em->persist($this->user);
        $this->em->flush();

        $this->client->loginUser($this->user);
    }

    /** Reads the token out of the rendered widget, because a token minted here is not the session's. */
    private function csrf(): string
    {
        $crawler = $this->client->request('GET', '/status');

        return $crawler->filter('input[name="_op_token"]')->first()->attr('value');
    }

    private function statusService(): OperationalStatusService
    {
        return static::getContainer()->get(OperationalStatusService::class);
    }

    public function testWidgetRendersCurrentStatus(): void
    {
        $this->client->request('GET', '/status');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.nav-status-label', 'No Shifts');
    }

    public function testSetAndClearFreeToHelp(): void
    {
        $this->client->request('POST', '/status/free-to-help', [
            '_op_token' => $this->csrf(),
            'minutes' => 60,
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(OperationalStatusService::FREE_TO_HELP, $this->statusService()->effectiveStatus($this->user));

        $this->client->request('POST', '/status/clear', [
            '_op_token' => $this->csrf(),
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(OperationalStatusService::NO_SHIFTS, $this->statusService()->effectiveStatus($this->user));
    }

    public function testInvalidCsrfIsIgnored(): void
    {
        $this->client->request('POST', '/status/free-to-help', [
            '_token' => 'wrong',
            'minutes' => 60,
        ]);

        self::assertSame(OperationalStatusService::NO_SHIFTS, $this->statusService()->effectiveStatus($this->user));
    }
}
