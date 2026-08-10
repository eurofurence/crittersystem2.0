<?php

namespace App\Tests\Feature;

use App\Tests\DatabaseWebTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The pointer from the volunteer shift list to the staff shift-apply screen.
 *
 * Staff take shifts on their own screen, which shows every department at once, and nothing on
 * /shifts said so. The banner is gated on the privilege that screen enforces rather than on the
 * staff role, so following it can never end in an access-denied page.
 */
final class StaffApplyBannerTest extends DatabaseWebTestCase
{
    private ShiftScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = new ShiftScenario($this->em, static::getContainer()->get(UserPasswordHasherInterface::class));
    }

    public function testStaffAreOfferedTheStaffApplyScreen(): void
    {
        $user = $this->scenario->user(['shift:view', 'shift:self', 'manageshifts:view'], $this->scenario->type, 'ROLE_STAFF');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/shifts');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="/manage-shifts/apply"]'));
    }

    public function testAPlainVolunteerIsNotOfferedIt(): void
    {
        $user = $this->scenario->user(['shift:view', 'shift:self'], $this->scenario->type);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/shifts');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href="/manage-shifts/apply"]'));
    }

    /**
     * The banner and the screen must agree: whoever is offered the link can open it.
     */
    public function testTheOfferedLinkIsReachable(): void
    {
        $user = $this->scenario->user(['shift:view', 'shift:self', 'manageshifts:view'], $this->scenario->type, 'ROLE_STAFF');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/shifts');
        $this->client->click($crawler->filter('a[href="/manage-shifts/apply"]')->link());

        self::assertResponseIsSuccessful();
    }
}
