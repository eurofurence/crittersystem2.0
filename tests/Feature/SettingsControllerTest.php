<?php

namespace App\Tests\Feature;

use App\Entity\Contact;
use App\Entity\PersonalData;
use App\Entity\Settings;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SettingsControllerTest extends DatabaseWebTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setName('settu')->setEmail('settu@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $this->user->setPassword($hasher->hashPassword($this->user, 'secret123'));
        $this->user->setPersonalData(new PersonalData($this->user))
            ->setContact(new Contact($this->user))
            ->setSettings(new Settings($this->user));
        $this->user->completeOnboarding();
        $this->em->persist($this->user);
        $this->em->flush();

        $this->client->loginUser($this->user);
    }

    private function reloadUser(): User
    {
        $this->em->clear();

        return $this->em->getRepository(User::class)->find($this->user->getId());
    }

    public function testRenders(): void
    {
        $this->client->request('GET', '/settings');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Account settings');
    }

    public function testSaveProfileFields(): void
    {
        $crawler = $this->client->request('GET', '/settings');
        $form = $crawler->selectButton('Save settings')->form();
        $form['account_settings[pronoun]'] = 'they/them';
        $form['account_settings[language]'] = 'de_DE';
        $this->client->submit($form);
        self::assertResponseRedirects('/settings');

        $reloaded = $this->reloadUser();
        self::assertSame('they/them', $reloaded->getPersonalData()->getPronoun());
        self::assertSame('de_DE', $reloaded->getSettings()->getLanguage());
    }

    public function testPasswordChangeRejectsWrongCurrent(): void
    {
        $crawler = $this->client->request('GET', '/settings');
        $form = $crawler->selectButton('Save settings')->form();
        $form['account_settings[currentPassword]'] = 'wrongpass';
        $form['account_settings[newPassword]'] = 'brandnewpass1';
        $this->client->submit($form);

        // Invalid form (wrong current password) -> 422, not a redirect.
        self::assertResponseStatusCodeSame(422);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($this->reloadUser(), 'secret123'));
    }

    public function testPasswordChangeSucceedsWithCorrectCurrent(): void
    {
        $crawler = $this->client->request('GET', '/settings');
        $form = $crawler->selectButton('Save settings')->form();
        $form['account_settings[currentPassword]'] = 'secret123';
        $form['account_settings[newPassword]'] = 'brandnewpass1';
        $this->client->submit($form);
        self::assertResponseRedirects('/settings');

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($this->reloadUser(), 'brandnewpass1'));
    }
}
