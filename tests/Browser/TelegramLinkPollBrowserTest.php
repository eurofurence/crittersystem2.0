<?php

namespace App\Tests\Browser;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Settings;
use App\Entity\TelegramConfiguration;
use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The link-status poll must not outlive the page that started it.
 *
 * An inline loop keeps hitting the status endpoint every few seconds with a Referer pointing at a
 * completely different page, because Turbo swaps the body without tearing the document down and
 * nothing owns the timer. Only a real browser can show this: the request log is the symptom, and
 * PHPUnit never runs the script at all.
 */
final class TelegramLinkPollBrowserTest extends BrowserTestCase
{
    private function enableTelegram(): void
    {
        $config = new TelegramConfiguration();
        $config->setEnabled(true)->setBotUsername('MyEventBot')->setApiKey('secret-token');
        $this->em->persist($config);
    }

    private function newcomer(): User
    {
        $suffix = bin2hex(random_bytes(4));
        $group = new Group('Grp '.$suffix, 'grp-'.$suffix, 'ROLE_USER');
        foreach (['news:view', 'shift:view'] as $name) {
            $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => $name]) ?? new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('tg-'.$suffix)->setEmail('tg-'.$suffix.'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $this->em->persist($user);

        $settings = new Settings($user);
        $user->setSettings($settings);
        $this->em->persist($settings);
        $this->em->flush();

        return $user;
    }

    /**
     * Leaves the step by submitting its own form, which Turbo intercepts. It has to be a Turbo visit
     * and not a driver-level navigation: the driver loads a brand new document, which destroys the
     * counter and lets this test pass against the very bug it exists to catch.
     */
    public function testThePollStopsWhenTheUserNavigatesAway(): void
    {
        $this->enableTelegram();
        $user = $this->newcomer();
        $this->em->flush();

        $this->browse();
        $this->signIn($user, 'secret123');

        $this->client->request('GET', '/onboarding/telegram');
        $this->client->waitFor('#tg-wait', 10);

        $this->client->executeScript(<<<'JS'
            window.__statusHits = 0;
            const original = window.fetch;
            window.fetch = function (...args) {
                if (String(args[0]).includes('/telegram/status')) {
                    window.__statusHits += 1;
                }
                return original.apply(this, args);
            };
            JS);

        usleep(7_000_000);
        self::assertGreaterThan(
            0,
            (int) $this->client->executeScript('return window.__statusHits;'),
            'the step must poll while it is on screen',
        );

        $this->client->submitForm('Skip for now');
        $this->client->waitForElementToNotContain('body', 'MyEventBot', 10);

        $survived = $this->client->executeScript('return typeof window.__statusHits !== "undefined";');
        self::assertTrue(
            (bool) $survived,
            'Turbo replaced the whole document, so this test cannot observe a leaked timer - it needs a real Turbo visit to be meaningful',
        );

        $onLeave = (int) $this->client->executeScript('return window.__statusHits;');
        usleep(7_000_000);
        $after = (int) $this->client->executeScript('return window.__statusHits;');

        self::assertSame(
            $onLeave,
            $after,
            'the status poll kept running after the user left the Telegram step',
        );
        $this->assertNoConsoleErrors('the Telegram link step');
    }
}
