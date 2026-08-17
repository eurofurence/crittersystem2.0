<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The board is left open all day on several machines and re-renders on every change in the
 * department, so its cost has to be flat in the size of the day rather than per shift or per person.
 *
 * Asserted as a ceiling rather than an exact count so unrelated work on the page does not make this
 * brittle; what it protects against is a relation being walked per row, which is the difference
 * between a handful of queries and several hundred.
 */
final class BoardQueryCostTest extends DatabaseWebTestCase
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

    private function boardUser(): User
    {
        $suffix = bin2hex(random_bytes(4));
        $group = new Group('Board '.$suffix, 'board-'.$suffix, 'ROLE_STAFF');
        $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => 'board:view']) ?? new Privilege('board:view');
        $this->em->persist($privilege);
        $group->addPrivilege($privilege);
        $this->em->persist($group);

        $user = new User();
        $user->setName('board-'.$suffix)->setEmail('board-'.$suffix.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'secret123'));
        $user->completeOnboarding();
        $this->em->persist($user->assignGroup($group, $this->scenario->department));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function seedDay(int $shifts, int $volunteersPerShift): void
    {
        $pool = [];
        for ($i = 0; $i < $shifts * $volunteersPerShift; ++$i) {
            $pool[] = $this->scenario->user();
        }

        $day = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        for ($s = 0; $s < $shifts; ++$s) {
            /** @var Shift $shift */
            $shift = $this->scenario->shift('Slot '.$s, 'today 00:00', '+1 hour', 3);
            $shift->setStartsAt($day->modify(sprintf('+%d minutes', $s * 20)))
                ->setEndsAt($day->modify(sprintf('+%d minutes', $s * 20 + 120)));

            for ($v = 0; $v < $volunteersPerShift; ++$v) {
                $this->scenario->signUp($pool[$s * $volunteersPerShift + $v], $shift);
            }
        }
        $this->em->flush();
    }

    /**
     * The query count for a board day must not grow per shift or per assignment. The first
     * request warms whatever caches the page touches and is not profiled; the steady state
     * is what the bound applies to.
     */
    public function testRenderingCostStaysFlatInTheSizeOfTheDay(): void
    {
        $this->seedDay(40, 3);
        $this->client->loginUser($this->boardUser());

        $url = '/board/'.$this->scenario->department->getUuid().'/'.(new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->format('Y-m-d');

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $this->client->enableProfiler();
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $queries = $this->client->getProfile()->getCollector('db')->getQueryCount();
        self::assertLessThan(
            40,
            $queries,
            sprintf('a day of 40 shifts and 120 assignments took %d queries; it must not grow per row', $queries),
        );
    }
}
