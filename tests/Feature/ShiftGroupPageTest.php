<?php

namespace App\Tests\Feature;

use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\ShiftGroup;
use App\Enum\ShiftAudience;
use App\Tests\DatabaseWebTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The volunteer-facing surfaces of shift groups: the badge on the browse list, the confirmation
 * dialog body, and the sign-up round trip through HTTP.
 */
final class ShiftGroupPageTest extends DatabaseWebTestCase
{
    private ShiftScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = new ShiftScenario(
            $this->em,
            static::getContainer()->get(UserPasswordHasherInterface::class),
        );
    }

    /** @param Shift[] $shifts */
    private function group(array $shifts, string $name = 'Main Show'): ShiftGroup
    {
        $group = new ShiftGroup($this->scenario->department, $name);
        $this->em->persist($group);
        foreach ($shifts as $shift) {
            $group->addShift($shift);
        }
        $this->em->flush();

        return $group;
    }

    /**
     * The browse list has to say a shift is grouped before the volunteer clicks it. Only published
     * public-audience shifts reach this page at all, which is the one shape that can appear here.
     */
    public function testTheBrowseListMarksAGroupedShift(): void
    {
        $rehearsal = $this->scenario->shift('Show rehearsal', 'tomorrow 12:00');
        $show = $this->scenario->shift('Main event', 'tomorrow 15:00');
        $this->group([$rehearsal, $show], 'Main Show');

        $user = $this->scenario->user(['shift:view', 'shift:self'], $this->scenario->type);
        $this->client->loginUser($user);
        $this->client->request('GET', '/shifts?date='.(new \DateTimeImmutable('tomorrow'))->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Show rehearsal', $html);
        self::assertStringContainsString('Main Show', $html, 'The card must name the group it belongs to.');
    }

    /**
     * Staff-audience shifts never reach /shifts; they are applied to from the staff shift manager.
     * That path has its own row template and its own service, so it needs its own proof that the
     * group is both shown and enforced.
     */
    public function testTheStaffApplyScreenMarksAndEnforcesAGroup(): void
    {
        $rehearsal = $this->scenario->shift('Staff rehearsal', 'tomorrow 12:00');
        $show = $this->scenario->shift('Staff main event', '+2 days 09:00');
        foreach ([$rehearsal, $show] as $shift) {
            $shift->setAudience(ShiftAudience::DEPARTMENT_STAFF);
        }
        $this->em->flush();
        $this->group([$rehearsal, $show], 'Staff Show');

        $user = $this->scenario->user(
            ['shift:view', 'shift:apply', 'shift:self', 'manageshifts:view'],
            $this->scenario->type,
            'ROLE_STAFF',
        );
        $this->scenario->departmentMember($user);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/manage-shifts/apply');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Staff Show',
            (string) $this->client->getResponse()->getContent(),
            'The staff apply row must name the group.',
        );

        $this->client->submit($crawler->filter('form[action*="/manage-shifts/apply/"]')->first()->form());

        self::assertSame(
            2,
            $this->em->getRepository(ShiftEntry::class)->count(['user' => $user->getId()]),
            'Applying from the staff screen takes the whole group.',
        );
    }

    public function testTheShiftPageListsTheOtherShiftsInTheGroup(): void
    {
        $rehearsal = $this->scenario->shift('Show rehearsal', 'tomorrow 12:00');
        $show = $this->scenario->shift('Main event', '+2 days 09:00');
        $this->group([$rehearsal, $show]);

        $user = $this->scenario->user(['shift:view', 'shift:self'], $this->scenario->type);
        $this->client->loginUser($user);
        $this->client->request('GET', '/shifts/'.$show->getUuid());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Show rehearsal');
        self::assertSelectorTextContains('body', 'Main Show');
    }

    public function testTheDialogBodyDescribesEveryMember(): void
    {
        $rehearsal = $this->scenario->shift('Show rehearsal', 'tomorrow 12:00');
        $show = $this->scenario->shift('Main event', '+2 days 09:00');
        $this->group([$rehearsal, $show]);

        $user = $this->scenario->user(['shift:view', 'shift:self'], $this->scenario->type);
        $this->client->loginUser($user);
        $this->client->request('GET', '/shifts/'.$show->getUuid().'/group');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Show rehearsal', $html);
        self::assertStringContainsString('Main event', $html);
        // A full page would mean the fragment route rendered the layout, which the dialog would then
        // inject wholesale.
        self::assertStringNotContainsString('<html', $html);
    }

    /**
     * A group holding a shift the viewer may not see is refused as a whole, and the hidden member is
     * never named in the response.
     */
    public function testTheDialogNeverNamesAMemberTheViewerCannotSee(): void
    {
        $show = $this->scenario->shift('Main event', '+2 days 09:00');
        $secret = $this->scenario->shift('Secret briefing', 'tomorrow 12:00');
        $secret->setAudience(ShiftAudience::ALL_STAFF);
        $this->em->flush();
        $this->group([$secret, $show]);

        $user = $this->scenario->user(['shift:view', 'shift:self'], $this->scenario->type);
        $this->client->loginUser($user);
        $this->client->request('GET', '/shifts/'.$show->getUuid().'/group');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Secret briefing', (string) $this->client->getResponse()->getContent());
    }

    public function testSigningUpThroughTheFormCreatesEveryEntry(): void
    {
        $rehearsal = $this->scenario->shift('Show rehearsal', 'tomorrow 12:00');
        $show = $this->scenario->shift('Main event', '+2 days 09:00');
        $this->group([$rehearsal, $show]);

        $user = $this->scenario->user(['shift:view', 'shift:apply', 'shift:self'], $this->scenario->type);
        $this->client->loginUser($user);

        // Submitted through the rendered form: the CSRF token is minted into the session by the page
        // itself, and building one outside a request has no session to mint it in.
        $crawler = $this->client->request('GET', '/shifts/'.$show->getUuid());
        $this->client->submit($crawler->filter('form[action*="/signup"]')->form());

        self::assertResponseRedirects();
        self::assertSame(2, $this->em->getRepository(ShiftEntry::class)->count(['user' => $user->getId()]));
    }

    public function testCancellingThroughTheFormRemovesEveryEntry(): void
    {
        $rehearsal = $this->scenario->shift('Show rehearsal', 'tomorrow 12:00');
        $show = $this->scenario->shift('Main event', '+2 days 09:00');
        $this->group([$rehearsal, $show]);

        $user = $this->scenario->user(['shift:view', 'shift:apply', 'shift:self'], $this->scenario->type);
        $this->scenario->signUp($user, $rehearsal);
        $this->scenario->signUp($user, $show);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/shifts/'.$show->getUuid());
        $this->client->submit($crawler->filter('form[action*="/cancel"]')->form());

        self::assertResponseRedirects();
        self::assertSame(0, $this->em->getRepository(ShiftEntry::class)->count(['user' => $user->getId()]));
    }

    public function testTheGroupDialogIsNotReachableForAShiftTheViewerCannotSee(): void
    {
        $shift = $this->scenario->shift('Staff only', 'tomorrow 12:00');
        $shift->setAudience(ShiftAudience::ALL_STAFF);
        $this->em->flush();

        $user = $this->scenario->user(['shift:view', 'shift:self'], $this->scenario->type);
        $this->client->loginUser($user);
        $this->client->request('GET', '/shifts/'.$shift->getUuid().'/group');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }
}
