<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Repository\UserVolunteerTypeRepository;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Protects the manager-side "put this volunteer in this critter type" path: who may reach it,
 * that the added membership needs no second approval, and that the picker it feeds does not
 * become a directory for people who only support one type.
 */
final class VolunteerTypeMemberAddTest extends DatabaseWebTestCase
{
    private VolunteerType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->type = new VolunteerType('Runner');
        $this->em->persist($this->type);
        $this->em->flush();
    }

    /** @param string[] $privileges */
    private function user(string $name, array $privileges = [], ?string $role = null): User
    {
        $group = new Group('G'.$name, 'g-'.$name, $role);
        foreach ($privileges as $p) {
            $priv = new Privilege($p);
            $this->em->persist($priv);
            $group->addPrivilege($priv);
        }
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

    private function manager(): User
    {
        return $this->user('boss', ['volunteertype:manage'], 'ROLE_STAFF');
    }

    private function memberships(): UserVolunteerTypeRepository
    {
        return static::getContainer()->get(UserVolunteerTypeRepository::class);
    }

    private function addToken(): string
    {
        $crawler = $this->client->request('GET', '/manage/volunteer-types/'.$this->type->getUuid().'/members');
        self::assertResponseIsSuccessful();

        return $crawler->filter('form[action$="/members/add"] input[name="_token"]')->attr('value');
    }

    public function testManagerAddsUsersAndTheyAreConfirmedWithoutApplying(): void
    {
        $this->client->loginUser($this->manager());
        $anna = $this->user('anna');
        $ben = $this->user('ben');

        $this->client->request('POST', '/manage/volunteer-types/'.$this->type->getUuid().'/members/add', [
            '_token' => $this->addToken(),
            'users' => [(string) $anna->getUuid(), (string) $ben->getUuid()],
        ]);
        self::assertResponseRedirects('/manage/volunteer-types/'.$this->type->getUuid().'/members');

        $this->em->clear();
        $type = $this->em->getRepository(VolunteerType::class)->findOneBy(['name' => 'Runner']);
        $members = $this->memberships()->findByVolunteerType($type);
        $names = array_map(fn (UserVolunteerType $m) => $m->getUser()->getName(), $members);
        sort($names);
        self::assertSame(['anna', 'ben'], $names);
        foreach ($members as $membership) {
            self::assertTrue($membership->isConfirmed(), 'a manager adding someone is the approval');
            self::assertFalse($membership->isSupporter());
        }
    }

    /** Both shapes of a repeat: the same uuid twice in one submission, and a second submission. */
    public function testAddingSomeoneTwiceDoesNotDuplicateTheMembership(): void
    {
        $this->client->loginUser($this->manager());
        $anna = $this->user('anna');

        $this->client->request('POST', '/manage/volunteer-types/'.$this->type->getUuid().'/members/add', [
            '_token' => $this->addToken(),
            'users' => [(string) $anna->getUuid(), (string) $anna->getUuid()],
        ]);
        $this->client->request('POST', '/manage/volunteer-types/'.$this->type->getUuid().'/members/add', [
            '_token' => $this->addToken(),
            'users' => [(string) $anna->getUuid()],
        ]);

        $this->em->clear();
        $type = $this->em->getRepository(VolunteerType::class)->findOneBy(['name' => 'Runner']);
        self::assertCount(1, $this->memberships()->findByVolunteerType($type));
    }

    public function testAddIsRefusedWithoutAValidCsrfToken(): void
    {
        $this->client->loginUser($this->manager());
        $anna = $this->user('anna');

        $this->client->request('POST', '/manage/volunteer-types/'.$this->type->getUuid().'/members/add', [
            '_token' => 'not-the-token',
            'users' => [(string) $anna->getUuid()],
        ]);

        $this->em->clear();
        $type = $this->em->getRepository(VolunteerType::class)->findOneBy(['name' => 'Runner']);
        self::assertSame([], $this->memberships()->findByVolunteerType($type));
    }

    public function testSupporterOfTheTypeMayAddButAnOrdinaryMemberMayNot(): void
    {
        $supporter = $this->user('supp');
        $membership = new UserVolunteerType($supporter, $this->type);
        $membership->setSupporter(true)->setConfirmedBy($supporter);
        $this->em->persist($membership);

        $plain = $this->user('plain');
        $plainMembership = new UserVolunteerType($plain, $this->type);
        $plainMembership->setConfirmedBy($plain);
        $this->em->persist($plainMembership);
        $this->em->flush();

        $anna = $this->user('anna');

        $this->client->loginUser($supporter);
        $this->client->request('POST', '/manage/volunteer-types/'.$this->type->getUuid().'/members/add', [
            '_token' => $this->addToken(),
            'users' => [(string) $anna->getUuid()],
        ]);
        self::assertResponseRedirects();
        self::assertNotNull($this->memberships()->findOneByUserAndType($anna, $this->type));

        $this->client->loginUser($plain);
        $this->client->request('GET', '/manage/volunteer-types/'.$this->type->getUuid().'/members');
        self::assertResponseStatusCodeSame(403, 'membership alone does not let you manage the type');
    }

    /**
     * The picker is reachable by every supporter of a type, so an e-mail substring must not
     * resolve through it. Widening the match would turn it into an address harvester.
     */
    public function testSearchIsNameOnlyExcludesMembersAndIsClosedToOutsiders(): void
    {
        $this->client->loginUser($this->manager());
        $this->user('alice');
        $this->user('alistair');
        $this->user('bob');

        $url = '/manage/volunteer-types/'.$this->type->getUuid().'/members/search';
        $this->client->request('GET', $url.'?q=ali');
        self::assertResponseIsSuccessful();
        $names = array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['results'], 'name');
        sort($names);
        self::assertSame(['alice', 'alistair'], $names);

        $this->client->request('GET', $url.'?q=example.com');
        self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true)['results']);

        $alice = $this->em->getRepository(User::class)->findOneBy(['name' => 'alice']);
        $type = $this->em->getRepository(VolunteerType::class)->find($this->type->getId());
        $membership = new UserVolunteerType($alice, $type);
        $membership->setConfirmedBy($alice);
        $this->em->persist($membership);
        $this->em->flush();

        $this->client->request('GET', $url.'?q=ali');
        $names = array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['results'], 'name');
        self::assertSame(['alistair'], $names, 'an existing member is not offered again');

        $this->client->loginUser($this->user('outsider'));
        $this->client->request('GET', $url.'?q=ali');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Adding a volunteer who holds no staff role to a staff-only type still goes through, because
     * the manager decides, but the manager is warned that the volunteer cannot see the type.
     */
    public function testAddingAUserWhoCannotSeeAStaffOnlyTypeWarnsTheManager(): void
    {
        $this->type->setStaffOnly(true);
        $this->em->flush();

        $this->client->loginUser($this->manager());
        $volunteer = $this->user('anna');

        $this->client->request('POST', '/manage/volunteer-types/'.$this->type->getUuid().'/members/add', [
            '_token' => $this->addToken(),
            'users' => [(string) $volunteer->getUuid()],
        ]);
        $crawler = $this->client->followRedirect();

        self::assertNotNull($this->memberships()->findOneByUserAndType($volunteer, $this->type), 'the manager decides, so the add still happens');
        self::assertStringContainsString('anna', $crawler->filter('.alert-warning')->text(), 'but the unreachable membership is flagged');
    }

    /**
     * The member actions must not sit inside the type's own form: a browser drops a nested
     * <form> while parsing, so the add and remove buttons would submit the edit form instead.
     */
    public function testTheEditPageCarriesTheSameMemberManagement(): void
    {
        $this->client->loginUser($this->manager());
        $anna = $this->user('anna');
        $membership = new UserVolunteerType($anna, $this->type);
        $membership->setConfirmedBy($anna);
        $this->em->persist($membership);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/manage/volunteer-types/'.$this->type->getUuid().'/edit');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[action$="/members/add"]'), 'the add picker is on the edit page');
        self::assertCount(1, $crawler->filter('form[action$="/remove"]'), 'and so is the per-member remove');
        self::assertStringContainsString('anna', $crawler->text());

        self::assertCount(0, $crawler->filterXPath('//form[ancestor::form]'));
    }

    public function testTheNewTypePageHasNoMemberManagement(): void
    {
        $this->client->loginUser($this->manager());

        $crawler = $this->client->request('GET', '/manage/volunteer-types/new');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action$="/members/add"]'));
    }
}
