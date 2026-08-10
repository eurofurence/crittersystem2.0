<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Service\EventConfigStore;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ManageOperationsConfigTest extends DatabaseWebTestCase
{
    private function loginAdmin(): void
    {
        $group = new Group('Admins', 'admins');
        $privilege = new Privilege('config:event');
        $this->em->persist($privilege);
        $group->addPrivilege($privilege);
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('opsadmin')->setEmail('opsadmin@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    private function store(): EventConfigStore
    {
        return static::getContainer()->get(EventConfigStore::class);
    }

    public function testRendersWithDefaults(): void
    {
        $this->loginAdmin();
        $this->client->request('GET', '/manage/operations');

        self::assertResponseIsSuccessful();
    }

    public function testSavingRoundTrips(): void
    {
        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/manage/operations');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save')->form();
        $form['operations_config[noShowThreshold]'] = '3';
        $form['operations_config[recommendedMaxHours]'] = '25';
        $form['operations_config[messagesEnabled]']->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/manage/operations');

        self::assertSame(3, $this->store()->getInt(EventConfigStore::KEY_BAN_NOSHOW_THRESHOLD, 2));
        self::assertSame(25, $this->store()->getInt(EventConfigStore::KEY_HOURS_RECOMMENDED_MAX, 20));
        self::assertTrue($this->store()->getBool(EventConfigStore::KEY_MESSAGES_ENABLED, false));
    }

    /**
     * A cleared textarea submits null, and the model behind this form types every one of them as a
     * non-nullable string: without empty_data the property accessor throws before validation runs
     * and the admin gets a 500 instead of a form telling them what is wrong.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('textAreaFields')]
    public function testClearingATextFieldNeverCrashesTheSave(string $field): void
    {
        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/manage/operations');

        $form = $crawler->selectButton('Save')->form();
        $form['operations_config['.$field.']'] = '';
        $this->client->submit($form);

        self::assertContains(
            $this->client->getResponse()->getStatusCode(),
            [302, 422],
            $field.' must either save or re-render with a violation, never fail',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function textAreaFields(): iterable
    {
        yield 'ban screen message' => ['banScreenMessage'];
        yield 'info desk welcome' => ['infoDeskWelcome'];
        yield 'info desk finalization' => ['infoDeskFinalization'];
        yield 'check-in message (English)' => ['checkInMessageEn'];
        yield 'check-in message (German)' => ['checkInMessageDe'];
    }

    public function testTheCheckInMessagesAreEditableInBothLanguages(): void
    {
        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/manage/operations');

        $form = $crawler->selectButton('Save')->form();
        $form['operations_config[checkInMessageEn]'] = 'Info desk, Hall 5.';
        $form['operations_config[checkInMessageDe]'] = 'Info-Desk, Halle 5.';
        $this->client->submit($form);

        self::assertResponseRedirects('/manage/operations');
        self::assertSame('Info desk, Hall 5.', $this->store()->getString(EventConfigStore::KEY_CHECKIN_MESSAGE_EN));
        self::assertSame('Info-Desk, Halle 5.', $this->store()->getString(EventConfigStore::KEY_CHECKIN_MESSAGE_DE));
    }

    /**
     * The English text is what every other locale falls back to, so it may not be blanked. The form
     * re-renders with the violation rather than saving, and never crashes on the null an empty
     * textarea submits.
     */
    public function testTheEnglishCheckInMessageIsRequired(): void
    {
        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/manage/operations');

        $form = $crawler->selectButton('Save')->form();
        $form['operations_config[checkInMessageEn]'] = '';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertNotSame('', $this->store()->getString(EventConfigStore::KEY_CHECKIN_MESSAGE_EN, EventConfigStore::DEFAULT_CHECKIN_MESSAGE_EN));
    }
}
