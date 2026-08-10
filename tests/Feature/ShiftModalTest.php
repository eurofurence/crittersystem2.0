<?php

namespace App\Tests\Feature;

use App\Entity\Certification;
use App\Entity\Group;
use App\Entity\NeededVolunteerType;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Enum\ShiftState;
use App\Repository\ShiftEntryRepository;
use App\Tests\DatabaseWebTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The shift dialog behind /shifts/{id}/group - the one place every surface applies, cancels and
 * explains a refusal from.
 *
 * Two things are load-bearing here. It must tell a volunteer who is offered nothing what they would
 * have to acquire, since a card with no controls is otherwise unexplained. And it must never offer a
 * route the viewer cannot follow: a volunteer type or certification they may not open is named
 * without a link, and the manage link is re-checked against the shift's own department, because
 * PrivilegeVoter grants unconditionally when it is handed no subject.
 */
final class ShiftModalTest extends DatabaseWebTestCase
{
    private ShiftScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = new ShiftScenario($this->em, static::getContainer()->get(UserPasswordHasherInterface::class));
    }

    private function modal(Shift $shift): \Symfony\Component\DomCrawler\Crawler
    {
        return $this->client->request('GET', '/shifts/'.$shift->getUuid().'/group');
    }

    /** A volunteer type this scenario's shift needs alongside the default one. */
    private function extraType(Shift $shift, string $name, bool $staffOnly = false): VolunteerType
    {
        $type = new VolunteerType($name);
        $type->setStaffOnly($staffOnly);
        $this->em->persist($type);

        $shift->addNeededVolunteerType(new NeededVolunteerType($type, 1));
        $this->em->flush();

        return $type;
    }

    public function testARoleTheVolunteerIsNotAMemberOfIsNamedAndLinked(): void
    {
        $shift = $this->scenario->shift('Gate Duty');
        $this->client->loginUser($this->scenario->user());

        $crawler = $this->modal($shift);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Demo Crew', $crawler->filter('body')->text());
        self::assertGreaterThan(
            0,
            $crawler->filter('a[href="/volunteer-types/'.$this->scenario->type->getUuid().'"]')->count(),
            'a role the volunteer could join must carry the link that lets them',
        );
    }

    public function testAnUnconfirmedMembershipIsReportedAsPending(): void
    {
        $shift = $this->scenario->shift('Gate Duty');
        $user = $this->scenario->user();
        $this->em->persist(new UserVolunteerType($user, $this->scenario->type)); // never confirmed
        $this->em->flush();
        $this->client->loginUser($user);

        self::assertStringContainsString('waiting for confirmation', $this->modal($shift)->filter('body')->text());
    }

    public function testAStaffOnlyVolunteerTypeIsNamedButNotLinked(): void
    {
        $shift = $this->scenario->shift('Gate Duty');
        $restricted = $this->extraType($shift, 'Secret Crew', staffOnly: true);
        $this->client->loginUser($this->scenario->user());

        $crawler = $this->modal($shift);

        self::assertStringContainsString('Secret Crew', $crawler->filter('body')->text());
        self::assertSame(
            0,
            $crawler->filter('a[href="/volunteer-types/'.$restricted->getUuid().'"]')->count(),
            'the volunteer type page 404s for a non-staff viewer, so the dialog must not link to it',
        );
    }

    public function testAMissingCertificationIsNamedAndLinked(): void
    {
        $shift = $this->scenario->shift('Gate Duty');
        $certification = new Certification('First Aid L2');
        $this->em->persist($certification);
        $this->scenario->type->addCertification($certification);
        $this->em->flush();

        $this->client->loginUser($this->scenario->user(memberOf: $this->scenario->type));

        $crawler = $this->modal($shift);

        self::assertStringContainsString('First Aid L2', $crawler->filter('body')->text());
        self::assertGreaterThan(
            0,
            $crawler->filter('a[href="/certifications/'.$certification->getUuid().'"]')->count(),
        );
    }

    public function testAStaffOnlyCertificationIsNamedButNotLinked(): void
    {
        $shift = $this->scenario->shift('Gate Duty');
        $certification = new Certification('Staff Only Cert');
        $certification->setStaffOnly(true);
        $this->em->persist($certification);
        $this->scenario->type->addCertification($certification);
        $this->em->flush();

        $this->client->loginUser($this->scenario->user(memberOf: $this->scenario->type));

        $crawler = $this->modal($shift);

        self::assertStringContainsString('Staff Only Cert', $crawler->filter('body')->text());
        self::assertSame(
            0,
            $crawler->filter('a[href="/certifications/'.$certification->getUuid().'"]')->count(),
            'a staff-only certification 404s for this viewer, so the dialog must not link to it',
        );
    }

    public function testAnUngroupedDialogDoesNotUseTheGroupWording(): void
    {
        $shift = $this->scenario->shift('Solo Shift');
        $this->client->loginUser($this->scenario->user(memberOf: $this->scenario->type));

        $text = $this->modal($shift)->filter('body')->text();

        self::assertStringNotContainsString('go together', $text);
        self::assertStringNotContainsString('all 1 ', $text);
    }

    public function testTheDialogIsNotFoundForAShiftTheViewerMayNotSee(): void
    {
        $shift = $this->scenario->shift('Secret Draft', state: ShiftState::DRAFT);
        $this->client->loginUser($this->scenario->user(memberOf: $this->scenario->type));

        $this->modal($shift);

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheManageLinkIsWithheldFromAManagerOfAnotherDepartment(): void
    {
        $shift = $this->scenario->shift('Gate Duty');
        $this->client->loginUser($this->foreignManager());

        $crawler = $this->client->request('GET', '/shifts?date='.$shift->getStartsAt()->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        self::assertSame(
            0,
            $crawler->filter('[data-shift-group-manage-url-value]')->count(),
            'shift:manage is department-scoped; a manager of another department must not be offered the shift',
        );
    }

    public function testTheManageLinkIsOfferedToTheDepartmentsOwnManager(): void
    {
        $shift = $this->scenario->shift('Gate Duty');
        $this->client->loginUser($this->ownManager());

        $crawler = $this->client->request('GET', '/shifts?date='.$shift->getStartsAt()->format('Y-m-d'));

        self::assertGreaterThan(
            0,
            $crawler->filter('[data-shift-group-manage-url-value]')->count(),
            'the scope check must not lock out the manager who owns the shift',
        );
    }

    /** What the dialog posts: no volunteer_type on the card, the chosen role under group_type. */
    public function testAVolunteerCanApplyWithTheRoleChosenInTheDialog(): void
    {
        $shift = $this->scenario->shift('Gate Duty');
        $user = $this->scenario->user(memberOf: $this->scenario->type);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/shifts?date='.$shift->getStartsAt()->format('Y-m-d'));
        $form = $crawler->filter('form[action*="/signup"]')->form();

        $this->client->request('POST', $form->getUri(), $form->getPhpValues() + [
            'group_type' => [(string) $shift->getUuid() => (string) $this->scenario->type->getUuid()],
        ]);

        self::assertResponseRedirects();
        self::assertNotNull($this->entries()->findOneByShiftAndUser($shift, $user));
    }

    public function testAVolunteerCanCancelFromTheCard(): void
    {
        $shift = $this->scenario->shift('Gate Duty');
        $user = $this->scenario->user(memberOf: $this->scenario->type);
        $this->scenario->signUp($user, $shift);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/shifts?date='.$shift->getStartsAt()->format('Y-m-d'));
        $this->client->submit($crawler->filter('form[action*="/cancel"]')->form());

        self::assertResponseRedirects();
        self::assertNull($this->entries()->findOneByShiftAndUser($shift, $user));
    }

    private function entries(): ShiftEntryRepository
    {
        return static::getContainer()->get(ShiftEntryRepository::class);
    }

    /** A shift manager scoped to a department that owns nothing in this scenario. */
    private function foreignManager(): User
    {
        $other = new \App\Entity\Department('Other', 'other-'.bin2hex(random_bytes(3)));
        $this->em->persist($other);

        return $this->manager($other);
    }

    private function ownManager(): User
    {
        return $this->manager($this->scenario->department);
    }

    private function manager(\App\Entity\Department $department): User
    {
        $suffix = bin2hex(random_bytes(4));
        $group = new Group('Mgr '.$suffix, 'mgr-'.$suffix, 'ROLE_STAFF');
        foreach (['shift:view', 'shift:self', 'shift:manage'] as $name) {
            $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => $name]) ?? new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $user = new User();
        $user->setName('mgr-'.$suffix)->setEmail('mgr-'.$suffix.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'secret123'));
        $user->completeOnboarding();
        $this->em->persist($user->assignGroup($group, $department));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
