<?php

namespace App\Tests\Browser;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Settings;
use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The onboarding theme step in a real browser.
 *
 * The preview is a full page reload rendered under a different stylesheet, which markup assertions
 * cannot see: they read the HTML the server sent, not the theme the browser actually applied. This
 * checks the document really switches theme and that the step raises no console error.
 */
final class OnboardingThemeBrowserTest extends BrowserTestCase
{
    private function newcomer(): User
    {
        $suffix = bin2hex(random_bytes(4));
        $group = new Group('Grp '.$suffix, 'grp-'.$suffix, 'ROLE_USER');
        $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => 'news:view']) ?? new Privilege('news:view');
        $this->em->persist($privilege);
        $group->addPrivilege($privilege);
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('theme-'.$suffix)->setEmail('theme-'.$suffix.'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $this->em->persist($user);

        $settings = new Settings($user);
        $user->setSettings($settings);
        $this->em->persist($settings);
        $this->em->flush();

        return $user;
    }

    public function testPreviewingADarkThemeSwitchesTheDocument(): void
    {
        $user = $this->newcomer();

        $this->browse();
        $this->signIn($user, 'secret123');

        $this->client->request('GET', '/onboarding/theme');
        $this->client->waitFor('form[method="post"] button', 10);

        $light = $this->client->executeScript('return document.documentElement.getAttribute("data-bs-theme");');

        $this->client->request('GET', '/onboarding/theme?theme=dark');
        $this->client->waitFor('form[method="post"] button', 10);
        $dark = $this->client->executeScript('return document.documentElement.getAttribute("data-bs-theme");');

        self::assertSame('dark', $dark, 'previewing the dark theme must actually render the page dark');
        self::assertNotSame($light, $dark, 'the preview must change what the browser renders');
        $this->assertNoConsoleErrors('the onboarding theme step');
    }

    public function testConfirmingTheThemeContinuesToTheFinalStep(): void
    {
        $user = $this->newcomer();

        $this->browse();
        $this->signIn($user, 'secret123');

        $this->client->request('GET', '/onboarding/theme?theme=dark');
        $this->client->waitFor('form[method="post"] button', 10);
        $this->client->submitForm('Confirm this theme');

        $this->client->waitForElementToContain('body', 'Almost done', 10);
        self::assertStringContainsString('/onboarding/finish', $this->client->getCurrentURL());
        $this->assertNoConsoleErrors('the onboarding finish step');
    }
}
