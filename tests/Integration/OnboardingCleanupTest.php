<?php

namespace App\Tests\Integration;

use App\Entity\Group;
use App\Entity\User;
use App\Tests\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class OnboardingCleanupTest extends DatabaseTestCase
{
    private function makeUser(string $name, ?string $role, bool $stale, bool $loggedIn = false): User
    {
        $group = new Group('G'.$name, 'g-'.$name, $role);
        $this->em->persist($group);

        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $user->addGroup($group);
        if ($loggedIn) {
            $user->setLastLoginAt(new \DateTimeImmutable('-2 days'));
        }
        $this->em->persist($user);
        $this->em->flush();

        if ($stale) {
            // Backdate creation past the 24h cutoff.
            $this->em->getConnection()->executeStatement(
                'UPDATE users SET created_at = :old WHERE id = :id',
                ['old' => (new \DateTimeImmutable('-2 days'))->format('Y-m-d H:i:sP'), 'id' => $user->getId()],
            );
        }

        return $user;
    }

    public function testRemovesOnlyStaleNeverUsedVolunteers(): void
    {
        $staleId = $this->makeUser('stale', null, stale: true)->getId();
        $recentId = $this->makeUser('recent', null, stale: false)->getId();
        $adminId = $this->makeUser('admin2', 'ROLE_ADMIN', stale: true)->getId();
        $usedId = $this->makeUser('used', null, stale: true, loggedIn: true)->getId();

        $tester = new CommandTester((new Application(self::$kernel))->find('app:onboarding:cleanup'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        $this->em->clear();

        $repo = $this->em->getRepository(User::class);
        self::assertNull($repo->find($staleId), 'stale never-used volunteer removed');
        self::assertNotNull($repo->find($recentId), 'recent volunteer kept');
        self::assertNotNull($repo->find($adminId), 'admin kept');
        self::assertNotNull($repo->find($usedId), 'logged-in account kept');
    }
}
