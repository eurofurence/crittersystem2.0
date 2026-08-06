<?php

namespace App\Tests\Feature;

use App\Entity\Certification;
use App\Entity\Group;
use App\Entity\Shift;
use App\Entity\User;
use App\Entity\UserCertification;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Service\Shift\ShiftEligibility;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A role that requires a certification is closed to volunteers who do not hold one.
 *
 * The rule is applied in two places on purpose. The role is not offered, so there is no button that
 * can only fail; and the sign-up is refused, so a request made any other way - the bot, a stale page,
 * a hand-written form post - lands on the same answer. The refusal names the certification, because
 * "you are not qualified" is not something a volunteer can do anything about.
 */
final class CertificationEnforcementTest extends DatabaseWebTestCase
{
    private function volunteer(string $name = 'vol'): User
    {
        $group = new Group('V-'.bin2hex(random_bytes(2)), 'v-'.bin2hex(random_bytes(3)), 'ROLE_USER');
        foreach (['shift:view', 'volunteertype:view'] as $privilegeName) {
            $privilege = $this->em->getRepository(\App\Entity\Privilege::class)->findOneBy(['name' => $privilegeName])
                ?? new \App\Entity\Privilege($privilegeName);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName($name.'-'.bin2hex(random_bytes(3)))->setEmail($name.'-'.bin2hex(random_bytes(3)).'@example.com');
        $user->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);

        return $user;
    }

    /**
     * A shift asking for one role, that role requiring one certification, and a confirmed member.
     *
     * @return array{0: Shift, 1: User, 2: VolunteerType, 3: Certification}
     */
    private function scenario(): array
    {
        $certification = new Certification('Certified Companion');
        $type = new VolunteerType('Companion');
        $type->addCertification($certification);
        $this->em->persist($certification);
        $this->em->persist($type);

        $volunteer = $this->volunteer();
        $membership = new UserVolunteerType($volunteer, $type);
        $membership->setConfirmedBy($volunteer);
        $this->em->persist($membership);

        $department = new \App\Entity\Department('Care', 'care-'.bin2hex(random_bytes(2)));
        $this->em->persist($department);

        $shift = (new Shift())->setTitle('Companion round')
            ->setStartsAt(new \DateTimeImmutable('+2 days 10:00'))
            ->setEndsAt(new \DateTimeImmutable('+2 days 12:00'))
            ->setDepartment($department)
            ->setState(\App\Enum\ShiftState::PUBLISHED);
        $need = new \App\Entity\NeededVolunteerType($type, 2);
        $shift->addNeededVolunteerType($need);
        $this->em->persist($shift);
        $this->em->persist($need);
        $this->em->flush();

        return [$shift, $volunteer, $type, $certification];
    }

    private function certify(User $user, Certification $certification, ?string $expires = '+1 year'): UserCertification
    {
        $record = new UserCertification($user, $certification);
        $record->setStatus(UserCertification::STATUS_APPROVED)->setDateCertified(new \DateTimeImmutable('-1 day'));
        if ($expires !== null) {
            $record->setDateExpires(new \DateTimeImmutable($expires));
        }
        $this->em->persist($record);
        $this->em->flush();

        return $record;
    }

    private function eligibility(): ShiftEligibility
    {
        return static::getContainer()->get(ShiftEligibility::class);
    }

    public function testTheRoleIsRefusedAndTheMissingCertificationIsNamed(): void
    {
        [$shift, $volunteer, $type, $certification] = $this->scenario();

        $error = $this->eligibility()->signUpError($volunteer, $shift, $type);

        self::assertNotNull($error);
        self::assertStringContainsString($certification->getTitle(), $error, 'the volunteer is told what to go and get');
    }

    public function testTheRoleIsNotEvenOffered(): void
    {
        [$shift, $volunteer] = $this->scenario();

        self::assertSame([], $this->eligibility()->signupOptions($shift, $volunteer));
        self::assertSame('ineligible', $this->eligibility()->eligibilityStatus($shift, $volunteer));
    }

    public function testHoldingItOpensTheRoleAgain(): void
    {
        [$shift, $volunteer, $type, $certification] = $this->scenario();
        $this->certify($volunteer, $certification);

        self::assertNull($this->eligibility()->signUpError($volunteer, $shift, $type));
        self::assertCount(1, $this->eligibility()->signupOptions($shift, $volunteer));
        self::assertSame('available', $this->eligibility()->eligibilityStatus($shift, $volunteer));
    }

    /** Somebody whose certificate ran out is not qualified today, which is why the expiry is kept. */
    public function testAnExpiredCertificationDoesNotCount(): void
    {
        [$shift, $volunteer, $type, $certification] = $this->scenario();
        $this->certify($volunteer, $certification, '-1 day');

        $error = $this->eligibility()->signUpError($volunteer, $shift, $type);

        self::assertNotNull($error);
        self::assertStringContainsString($certification->getTitle(), $error);
    }

    public function testARevokedCertificationDoesNotCount(): void
    {
        [$shift, $volunteer, $type, $certification] = $this->scenario();
        $record = $this->certify($volunteer, $certification);
        $record->setStatus(UserCertification::STATUS_REVOKED);
        $this->em->flush();

        self::assertNotNull($this->eligibility()->signUpError($volunteer, $shift, $type));
    }

    /** A pending application is not a certification, however patiently it is waiting. */
    public function testAPendingApplicationDoesNotCount(): void
    {
        [$shift, $volunteer, $type, $certification] = $this->scenario();
        $record = new UserCertification($volunteer, $certification);
        $record->setStatus(UserCertification::STATUS_PENDING);
        $this->em->persist($record);
        $this->em->flush();

        self::assertNotNull($this->eligibility()->signUpError($volunteer, $shift, $type));
    }

    public function testARoleThatRequiresNothingIsUnaffected(): void
    {
        [$shift, $volunteer] = $this->scenario();

        $plain = new VolunteerType('Runner');
        $this->em->persist($plain);
        $membership = new UserVolunteerType($volunteer, $plain);
        $membership->setConfirmedBy($volunteer);
        $this->em->persist($membership);
        $need = new \App\Entity\NeededVolunteerType($plain, 1);
        $shift->addNeededVolunteerType($need);
        $this->em->persist($need);
        $this->em->flush();

        self::assertNull($this->eligibility()->signUpError($volunteer, $shift, $plain));
    }

    /** A self-confirmed certification is held, so it opens the role like any other. */
    public function testASelfConfirmedCertificationCounts(): void
    {
        [$shift, $volunteer, $type, $certification] = $this->scenario();
        $record = new UserCertification($volunteer, $certification);
        $record->setStatus(UserCertification::STATUS_SELF_CONFIRMED)
            ->setDateCertified(new \DateTimeImmutable('-1 day'))
            ->setDateExpires(new \DateTimeImmutable('+1 year'));
        $this->em->persist($record);
        $this->em->flush();

        self::assertNull($this->eligibility()->signUpError($volunteer, $shift, $type));
    }

    /**
     * A manager may still place somebody - they might be holding the paper certificate as they type
     * - but they are told, and the entry records that the placement was an exception.
     */
    public function testAManagerIsWarnedRatherThanBlocked(): void
    {
        [$shift, $volunteer, $type, $certification] = $this->scenario();

        $assignments = static::getContainer()->get(\App\Service\Assignment\ManualAssignmentService::class);
        $inspection = $assignments->inspect($shift, $volunteer, $type);

        self::assertTrue($inspection['needsOverride']);
        $keys = array_column($inspection['warnings'], 'key');
        self::assertContains('certification', $keys);
        self::assertStringContainsString(
            $certification->getTitle(),
            implode(' ', array_column($inspection['warnings'], 'message')),
        );

        $entry = $assignments->assign($shift, $volunteer, $type, override: true);
        self::assertStringContainsString('certification', (string) $entry->getOverrideReason());
    }

    /** Nothing about this warning applies to a manager placing a volunteer who does hold it. */
    public function testAManagerPlacingACertifiedVolunteerIsNotWarned(): void
    {
        [$shift, $volunteer, $type, $certification] = $this->scenario();
        $this->certify($volunteer, $certification);

        $inspection = static::getContainer()->get(\App\Service\Assignment\ManualAssignmentService::class)
            ->inspect($shift, $volunteer, $type);

        self::assertNotContains('certification', array_column($inspection['warnings'], 'key'));
    }

    /** The volunteer meets the rule at the surface they actually use. */
    public function testTheShiftPageOffersNoSignUpWithoutTheCertification(): void
    {
        [$shift, $volunteer] = $this->scenario();
        $this->client->loginUser($volunteer);

        $crawler = $this->client->request('GET', '/shifts/'.$shift->getUuid());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action*="/signup"]'), 'no button that could only fail');
    }
}
