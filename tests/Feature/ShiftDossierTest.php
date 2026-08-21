<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Enum\ShiftState;
use App\Tests\DatabaseWebTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * What the shift dossier shows, and to whom.
 *
 * The dossier has two tiers. Everybody who may see a shift at all gets what it is and where; only
 * somebody answerable for it gets who planned it and who is on it. The privileged tier is decided
 * against the shift's own department, and PrivilegeVoter grants unconditionally when it is handed
 * no subject - so "does a foreign manager get the roster" is the question that has to be asked
 * directly, on the rendered markup rather than on a service return value, because a template can
 * still print what the presenter withheld.
 */
final class ShiftDossierTest extends DatabaseWebTestCase
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

    /**
     * A volunteer sees what the shift is, and nothing about who runs it. The creator's username, the
     * roster and the publication state are all absent.
     */
    public function testAVolunteerSeesNoCreatorAndNoRoster(): void
    {
        $shift = $this->scenario->shift('Morning Gate');
        $shift->setDescription('Hand out programmes at the main door.');
        $creator = $this->scenario->user(['shift:view']);
        $shift->setCreatedBy($creator);

        $roomMate = $this->scenario->user(['shift:view']);
        $this->scenario->signUp($roomMate, $shift);
        $this->em->flush();

        $viewer = $this->scenario->user(['shift:view'], $this->scenario->type);
        $this->client->loginUser($viewer);
        $this->client->request('GET', '/shifts/'.$shift->getUuid().'/info');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Hand out programmes', $body);
        self::assertStringNotContainsString($creator->getName(), $body);
        self::assertStringNotContainsString($roomMate->getName(), $body);
    }

    /**
     * The info desk holds shift:assign with no department scope, which is event-wide, so it reaches
     * the privileged tier on every department's shifts. That is the whole point of the screen.
     */
    public function testAnEventWideOperatorSeesTheCreatorAndTheRoster(): void
    {
        $shift = $this->scenario->shift('Morning Gate');
        $creator = $this->scenario->user(['shift:view']);
        $shift->setCreatedBy($creator);

        $volunteer = $this->scenario->user(['shift:view']);
        $this->scenario->signUp($volunteer, $shift);
        $this->em->flush();

        $operator = $this->scenario->user(['shift:view', 'shift:assign'], null, 'ROLE_STAFF');
        $this->client->loginUser($operator);
        $this->client->request('GET', '/shifts/'.$shift->getUuid().'/info');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString($creator->getName(), $body);
        self::assertStringContainsString($volunteer->getName(), $body);
    }

    /**
     * The check that fails open when the department is not passed as the subject: a manager scoped to
     * one department must get the descriptive tier, and only that, on another department's shift.
     */
    public function testAManagerScopedElsewhereDoesNotSeeTheRoster(): void
    {
        $shift = $this->scenario->shift('Morning Gate');
        $creator = $this->scenario->user(['shift:view']);
        $shift->setCreatedBy($creator);

        $volunteer = $this->scenario->user(['shift:view']);
        $this->scenario->signUp($volunteer, $shift);
        $this->em->flush();

        $foreign = new Department('Other', 'other-'.bin2hex(random_bytes(3)));
        $this->em->persist($foreign);
        $manager = $this->scopedManager($foreign, ['shift:view', 'shift:manage']);

        $this->client->loginUser($manager);
        $this->client->request('GET', '/shifts/'.$shift->getUuid().'/info');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString($creator->getName(), $body);
        self::assertStringNotContainsString($volunteer->getName(), $body);
    }

    /** The same manager, on a shift their own department owns, does get it. */
    public function testAManagerSeesTheRosterInTheirOwnDepartment(): void
    {
        $shift = $this->scenario->shift('Morning Gate');
        $volunteer = $this->scenario->user(['shift:view']);
        $this->scenario->signUp($volunteer, $shift);
        $this->em->flush();

        $manager = $this->scopedManager($this->scenario->department, ['shift:view', 'shift:manage']);

        $this->client->loginUser($manager);
        $this->client->request('GET', '/shifts/'.$shift->getUuid().'/info');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($volunteer->getName(), (string) $this->client->getResponse()->getContent());
    }

    /**
     * Marking somebody absent belongs on the shift, because that is the screen anybody looking for
     * it opens. It is offered on the roster only to a viewer the action itself would accept -
     * `shift:manage` scoped to this shift - because recording a no-show can lock the account.
     */
    public function testAManagerCanMarkANoShowFromTheRoster(): void
    {
        $shift = $this->scenario->shift('Morning Gate');
        $volunteer = $this->scenario->user(['shift:view']);
        $entry = $this->scenario->signUp($volunteer, $shift);

        $manager = $this->scopedManager($this->scenario->department, ['shift:view', 'shift:manage']);

        $this->client->loginUser($manager);
        $crawler = $this->client->request('GET', '/shifts/'.$shift->getUuid().'/info');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['/manage/shifts/entries/'.$entry->getUuid().'/noshow'],
            $crawler->filter('form[action*="/noshow"]')->extract(['action']),
        );

        $this->client->submit($crawler->filter('form[action*="/noshow"]')->form());

        self::assertResponseRedirects('/shifts/'.$shift->getUuid());

        $this->em->clear();
        self::assertTrue($this->em->getRepository(\App\Entity\ShiftEntry::class)->find($entry->getId())->isNoshow());
    }

    /**
     * The roster reaches further than the no-show control does: `assignment:manage` opens the
     * privileged tier, but the action enforces `shift:manage` and would refuse.
     */
    public function testTheNoShowControlIsWithheldFromAViewerTheActionWouldRefuse(): void
    {
        $shift = $this->scenario->shift('Morning Gate');
        $volunteer = $this->scenario->user(['shift:view']);
        $this->scenario->signUp($volunteer, $shift);

        $assigner = $this->scopedManager($this->scenario->department, ['shift:view', 'assignment:manage']);

        $this->client->loginUser($assigner);
        $crawler = $this->client->request('GET', '/shifts/'.$shift->getUuid().'/info');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($volunteer->getName(), (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $crawler->filter('form[action*="/noshow"]')->count());
    }

    /**
     * A draft is invisible to a volunteer, and the fragment must not become the way to confirm that
     * one exists: 404, never 403.
     */
    public function testADraftShiftIsNotFoundForAVolunteer(): void
    {
        $shift = $this->scenario->shift('Secret Setup', 'tomorrow 09:00', '+2 hours', 2, ShiftState::DRAFT);

        $this->client->loginUser($this->scenario->user(['shift:view'], $this->scenario->type));
        $this->client->request('GET', '/shifts/'.$shift->getUuid().'/info');

        self::assertResponseStatusCodeSame(404);
    }

    /** The gap the screen exists for is stated, not left blank. */
    public function testAMissingDescriptionIsCalledOut(): void
    {
        $shift = $this->scenario->shift('Morning Gate');

        $this->client->loginUser($this->scenario->user(['shift:view'], $this->scenario->type));
        $this->client->request('GET', '/shifts/'.$shift->getUuid().'/info');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No description was given', (string) $this->client->getResponse()->getContent());
    }

    /** The dossier also renders inside the shift page, which is the linkable half of the feature. */
    public function testTheShiftPageCarriesTheDossier(): void
    {
        $shift = $this->scenario->shift('Morning Gate');
        $creator = $this->scenario->user(['shift:view']);
        $shift->setCreatedBy($creator);
        $this->em->flush();

        $operator = $this->scenario->user(['shift:view', 'shift:assign'], null, 'ROLE_STAFF');
        $this->client->loginUser($operator);
        $this->client->request('GET', '/shifts/'.$shift->getUuid());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($creator->getName(), (string) $this->client->getResponse()->getContent());
    }

    /** @param string[] $privileges */
    private function scopedManager(Department $department, array $privileges): User
    {
        $suffix = bin2hex(random_bytes(4));
        $group = new Group('Mgr '.$suffix, 'mgr-'.$suffix, 'ROLE_STAFF');
        foreach ($privileges as $name) {
            $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => $name]) ?? new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $user = new User();
        $user->setName('mgr-'.$suffix)->setEmail('mgr-'.$suffix.'@example.com')->setPassword('x')
            ->setApiKey(bin2hex(random_bytes(16)));
        $user->completeOnboarding();
        $this->em->persist($user->assignGroup($group, $department));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
