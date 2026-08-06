<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A global volunteer type belongs to the whole event. The department form must not offer it, and
 * must not accept it when posted anyway: one department claiming Staff or Volunteer would leave
 * every other department without a role it needs to staff its shifts.
 */
final class GlobalVolunteerTypeTest extends DatabaseWebTestCase
{
    private function admin(): User
    {
        $group = new Group('Admins', 'admins-'.bin2hex(random_bytes(2)), 'ROLE_ADMIN');
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('adm-'.bin2hex(random_bytes(3)))->setEmail('adm-'.bin2hex(random_bytes(3)).'@example.com');
        $user->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        return $user;
    }

    /** @return array{0: Department, 1: VolunteerType, 2: VolunteerType} department, global type, ordinary type */
    private function scenario(): array
    {
        $department = new Department('Logistics', 'logistics-'.bin2hex(random_bytes(2)));
        $global = (new VolunteerType('Staff'))->setStaffOnly(true)->setHideOnShiftView(true)->setShowOnDashboard(false)->setGlobal(true);
        $ordinary = new VolunteerType('Rigging');
        $this->em->persist($department);
        $this->em->persist($global);
        $this->em->persist($ordinary);
        $this->em->flush();

        return [$department, $global, $ordinary];
    }

    public function testTheDepartmentFormDoesNotOfferAGlobalType(): void
    {
        $this->admin();
        [$department, $global, $ordinary] = $this->scenario();

        $crawler = $this->client->request('GET', '/manage/departments/'.$department->getUuid().'/edit');
        self::assertResponseIsSuccessful();

        $offered = $crawler->filter('select[name="department[volunteerTypes][]"] option')
            ->each(static fn ($o) => trim($o->text()));

        self::assertContains($ordinary->getName(), $offered);
        self::assertNotContains($global->getName(), $offered, 'a global type cannot be claimed');
    }

    public function testPostingAGlobalTypeDoesNotClaimIt(): void
    {
        $this->admin();
        [$department, $global] = $this->scenario();
        $globalId = $global->getId();

        $crawler = $this->client->request('GET', '/manage/departments/'.$department->getUuid().'/edit');
        $token = $crawler->filter('input[name="department[_token]"]')->attr('value');

        $this->client->request('POST', '/manage/departments/'.$department->getUuid().'/edit', [
            'department' => [
                '_token' => $token,
                'name' => $department->getName(),
                'slug' => $department->getSlug(),
                'volunteerTypes' => [(string) $globalId],
            ],
        ]);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Department::class)->findOneBy(['slug' => $department->getSlug()]);
        self::assertCount(0, $reloaded->getVolunteerTypes(), 'a posted global type is refused, not honoured');
    }

    /** Every department's pickers keep offering a global type, which is the point of the flag. */
    public function testAGlobalTypeStaysInEveryDepartmentsPicker(): void
    {
        $this->admin();
        [$department, $global, $ordinary] = $this->scenario();

        $other = new Department('Stage', 'stage-'.bin2hex(random_bytes(2)));
        $this->em->persist($other);
        $other->addVolunteerType($ordinary);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/manage-shifts/planner?department='.$department->getUuid());
        self::assertResponseIsSuccessful();

        $names = $crawler->filter('#planner-add-modal input[type="number"]')->each(static fn ($n) => $n->attr('name'));
        self::assertContains('needed['.$global->getUuid().']', $names);
        self::assertContains('needed['.$ordinary->getUuid().']', $names, 'and so does a type another department claimed');
    }
}
