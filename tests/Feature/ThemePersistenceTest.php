<?php

namespace App\Tests\Feature;

use App\Entity\Settings;
use App\Entity\User;
use App\Service\EventConfigStore;
use App\Tests\DatabaseWebTestCase;

/**
 * The theme a user picked must survive being used. It is resolved on every render from the user's
 * Settings, and ThemeResolver falls back to the administrator's default when anything goes wrong -
 * silently - so a fault there shows up as the theme quietly reverting rather than as an error.
 */
final class ThemePersistenceTest extends DatabaseWebTestCase
{
    private function user(string $theme): User
    {
        $user = new User();
        $user->setName('themer')->setEmail('themer@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $settings = new Settings($user);
        $settings->setTheme($theme);
        $user->setSettings($settings);
        $user->completeOnboarding();
        $this->em->persist($settings);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function themeOf(string $html): string
    {
        preg_match('~themes/([a-z0-9-]+?)(?:-[A-Za-z0-9_-]{7,})?\.css~', $html, $m);

        return $m[1] ?? '(none)';
    }

    /**
     * Saving a theme must actually save it. The controller skips its whole write block when the CSRF
     * check fails and still redirects, so a rejected token looks exactly like a successful save that
     * quietly reverts on the next page.
     */
    public function testSubmittingTheThemeFormPersistsTheChoice(): void
    {
        $user = $this->user('default');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/settings/theme');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Save')->form(['theme' => 'eurofurence']));
        self::assertResponseRedirects('/settings/theme');

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());

        self::assertSame(
            'eurofurence',
            $reloaded->getSettings()?->getTheme(),
            'the theme form redirected but stored nothing - the write block was skipped',
        );
    }

    public function testTheChosenThemeSurvivesRepeatedRequests(): void
    {
        // An administrator default that differs, so a revert is unmistakable.
        $store = static::getContainer()->get(EventConfigStore::class);
        $store->set(EventConfigStore::KEY_DEFAULT_THEME, 'default');
        $store->flush();

        $this->client->loginUser($this->user('eurofurence'));

        $seen = [];
        for ($i = 0; $i < 6; ++$i) {
            // Every real request starts with a cold EntityManager. Without this the identity map keeps
            // Settings loaded from the previous request and a broken lazy-load would never show.
            $this->em->clear();

            $this->client->request('GET', '/dashboard');
            self::assertResponseIsSuccessful();
            $seen[] = $this->themeOf((string) $this->client->getResponse()->getContent());
        }

        self::assertSame(
            array_fill(0, 6, 'eurofurence'),
            $seen,
            'the theme reverted to the administrator default part-way through: '.implode(', ', $seen),
        );
    }
}
