<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Service\EventConfigStore;
use App\Tests\DatabaseWebTestCase;

/**
 * The statistics dashboard as a page: who reaches it, that the totals render, and that the fun
 * section stays a separate, labelled surface.
 *
 * The separation is asserted rather than trusted. The estimates carry assumptions the base numbers
 * do not, and the whole point of the section is that an audience can tell the two apart.
 */
final class ManageStatisticsPageTest extends DatabaseWebTestCase
{
    /** @param string[] $privileges */
    private function makeUser(string $name, array $privileges = []): User
    {
        $group = new Group('Group '.$name, 'group-'.$name);
        foreach ($privileges as $privilegeName) {
            $privilege = new Privilege($privilegeName);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function seedWorkedShift(): void
    {
        $config = static::getContainer()->get(EventConfigStore::class);
        $config->set(EventConfigStore::KEY_BUILDUP_START, '2026-06-01T00:00:00+00:00');
        $config->set(EventConfigStore::KEY_TEARDOWN_END, '2026-06-10T00:00:00+00:00');
        $config->flush();

        $department = new Department('Ops', 'ops');
        $this->em->persist($department);
        $type = new VolunteerType('Helper');
        $this->em->persist($type);

        $shift = (new Shift())->setTitle('Door')
            ->setStartsAt(new \DateTimeImmutable('2026-06-02 10:00'))
            ->setEndsAt(new \DateTimeImmutable('2026-06-02 16:00'))
            ->setDepartment($department);
        $this->em->persist($shift);

        $worker = $this->makeUser('worker');
        $this->em->persist(new ShiftEntry($shift, $type, $worker));
        $this->em->flush();
    }

    public function testAnonymousIsSentToLogin(): void
    {
        $this->client->request('GET', '/manage/statistics');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testUserWithoutTheDashboardPrivilegeIsDenied(): void
    {
        $this->client->loginUser($this->makeUser('plain'));
        $this->client->request('GET', '/manage/statistics');

        self::assertResponseStatusCodeSame(403);
    }

    public function testDashboardShowsTheTotals(): void
    {
        $this->seedWorkedShift();
        $this->client->loginUser($this->makeUser('boss', ['global:dashboard']));

        $crawler = $this->client->request('GET', '/manage/statistics');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Base numbers');
        self::assertGreaterThan(0, $crawler->filter('.statistics-board')->count());
    }

    /** The estimates live in their own section, under their own warning. */
    public function testFunSectionIsSeparatedAndLabelledAsEstimates(): void
    {
        $this->seedWorkedShift();
        $this->client->loginUser($this->makeUser('boss', ['global:dashboard']));

        $crawler = $this->client->request('GET', '/manage/statistics');

        self::assertSame(1, $crawler->filter('.fun-section')->count());
        self::assertSelectorTextContains('.fun-section', 'Just for fun');
        self::assertStringContainsString('Estimates, not measurements', $crawler->filter('.fun-section')->text());

        $baseNumbers = $crawler->filter('.statistics-board > .row')->text();
        self::assertStringNotContainsString('Estimates, not measurements', $baseNumbers);
    }

    /** Nothing is hand-counted until an admin says so, and then it shows up. */
    public function testTalliesRoundTripFromTheFormToTheDashboard(): void
    {
        $this->seedWorkedShift();
        $this->client->loginUser($this->makeUser('boss', ['global:dashboard']));

        $crawler = $this->client->request('GET', '/manage/statistics');
        self::assertStringNotContainsString('bathtubs of coffee', $crawler->filter('.fun-section')->text());

        $crawler = $this->client->request('GET', '/manage/statistics/tallies');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save')->form();
        $form['statistics_tallies[known][coffee]'] = '400';
        $this->client->submit($form);

        self::assertResponseRedirects('/manage/statistics');
        $crawler = $this->client->followRedirect();

        $funText = $crawler->filter('.fun-section')->text();
        self::assertStringContainsString('400', $funText);
        self::assertStringContainsString('bathtubs of coffee', $funText);
    }

    /** A blank figure means "not counted", never zero. */
    public function testBlankTalliesStayOffTheDashboard(): void
    {
        $this->seedWorkedShift();
        $this->client->loginUser($this->makeUser('boss', ['global:dashboard']));

        $crawler = $this->client->request('GET', '/manage/statistics/tallies');
        $this->client->submit($crawler->selectButton('Save')->form());

        $crawler = $this->client->followRedirect();
        $funText = $crawler->filter('.fun-section')->text();

        self::assertStringNotContainsString('bathtubs of coffee', $funText);
        self::assertStringNotContainsString('pizzas', $funText);
    }
}
