<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\User;
use App\Entity\Worklog;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class WorklogSelfTest extends DatabaseWebTestCase
{
    private function makeUser(string $name, ?string $role = null): User
    {
        $group = new Group(ucfirst($name), $name.'-grp', $role);
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function worklog(User $subject, User $creator): Worklog
    {
        $worklog = (new Worklog($subject))->setCreator($creator)->setHours(2.0)
            ->setWorkedAt(new \DateTimeImmutable('-1 day'));
        $this->em->persist($worklog);
        $this->em->flush();

        return $worklog;
    }

    public function testStaffCanAddSelfWorklog(): void
    {
        $staff = $this->makeUser('worker', 'ROLE_STAFF');
        $this->client->loginUser($staff);

        $crawler = $this->client->request('GET', '/profile');
        $token = $crawler->filter('input[name="worklog_self[_token]"]')->attr('value');

        $this->client->request('POST', '/worklog/self', ['worklog_self' => [
            '_token' => $token,
            'hours' => '3.5',
            'workedAt' => '2026-07-10T09:00',
            'comment' => 'Late night teardown',
        ]]);

        self::assertResponseRedirects('/profile');
        $logs = $this->em->getRepository(Worklog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame($staff->getId(), $logs[0]->getUser()->getId());
        self::assertSame($staff->getId(), $logs[0]->getCreator()->getId());
    }

    public function testStaffCanDeleteOwnSelfWorklog(): void
    {
        $staff = $this->makeUser('worker', 'ROLE_STAFF');
        $log = $this->worklog($staff, $staff);
        $this->client->loginUser($staff);

        $crawler = $this->client->request('GET', '/profile');
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/worklog/'.$log->getUuid().'/delete', ['_token' => $token]);

        self::assertResponseRedirects('/profile');
        self::assertCount(0, $this->em->getRepository(Worklog::class)->findAll());
    }

    public function testStaffCannotEditManagerRecordedWorklog(): void
    {
        $staff = $this->makeUser('worker', 'ROLE_STAFF');
        $manager = $this->makeUser('boss', 'ROLE_STAFF');
        $log = $this->worklog($staff, $manager);
        $this->client->loginUser($staff);

        $this->client->request('GET', '/worklog/'.$log->getUuid().'/edit');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * The add-worklog modal posts to a Symfony form, whose CSRF token is stateless: the hidden
     * field must be the one the form theme renders, carrying the attributes the csrf-protection
     * controller looks for. A hand-written token field leaves the browser unable to double-submit,
     * and the post is then rejected for every session that has already validated one.
     */
    public function testSelfWorklogFormCarriesTheStatelessCsrfField(): void
    {
        $staff = $this->makeUser('worker', 'ROLE_STAFF');
        $this->client->loginUser($staff);

        $crawler = $this->client->request('GET', '/profile');
        $field = $crawler->filter('input[name="worklog_self[_token]"]');

        self::assertSame('csrf-protection', $field->attr('data-controller'));
        self::assertSame('csrf-token', $field->attr('value'));
    }

    public function testSelfWorklogSurvivesASessionThatAlreadyDoubleSubmitted(): void
    {
        $staff = $this->makeUser('worker', 'ROLE_STAFF');
        $log = $this->worklog($staff, $staff);
        $this->client->loginUser($staff);

        $crawler = $this->client->request('GET', '/worklog/'.$log->getUuid().'/edit');
        $this->client->submit($this->doubleSubmit($crawler->filter('form[name="worklog_self"]')->form(['worklog_self[hours]' => '1'])));
        self::assertResponseRedirects('/profile');

        $crawler = $this->client->request('GET', '/profile');
        $form = $crawler->filter('#add-worklog-modal form')->form([
            'worklog_self[hours]' => '3.5',
            'worklog_self[workedAt]' => '2026-07-10T09:00',
        ]);
        $this->client->submit($this->doubleSubmit($form));

        self::assertResponseRedirects('/profile');
        self::assertCount(2, $this->em->getRepository(Worklog::class)->findAll());
    }

    /**
     * Replays what assets/controllers/csrf_protection_controller.js does on submit: swap the
     * rendered sentinel for a random token and mirror it into a cookie.
     */
    private function doubleSubmit(Form $form): Form
    {
        $field = $form['worklog_self[_token]'];
        $cookieName = $field->getValue();
        $token = bin2hex(random_bytes(16));
        $field->setValue($token);
        $this->client->getCookieJar()->set(new Cookie($cookieName.'_'.$token, $cookieName, null, '/'));

        return $form;
    }

    public function testNonStaffCannotSelfReport(): void
    {
        $volunteer = $this->makeUser('vol');
        $this->client->loginUser($volunteer);

        $this->client->request('POST', '/worklog/self', ['worklog_self' => ['_token' => 'x', 'hours' => '1', 'workedAt' => '2026-07-10T09:00']]);
        self::assertResponseStatusCodeSame(403);
    }
}
