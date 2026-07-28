<?php

namespace App\Tests\Integration;

use App\EventSubscriber\LocaleSubscriber;
use PHPUnit\Framework\TestCase;

/**
 * Klingon is a novelty locale, and the way out of it must stay readable.
 *
 * Someone who picks it out of curiosity has to reach the user menu, open Settings and choose
 * another language. If any of those strings were themselves translated they would be trapped in a
 * language they cannot read, with no route back short of editing the database. The catalogue is
 * partial by design, so the guarantee is simply that certain keys are ABSENT - which is only a
 * guarantee if something checks.
 */
final class LocaleEscapeHatchTest extends TestCase
{
    /** Every key rendered on the path back to English. */
    private const PROTECTED_PREFIXES = [
        'ui.user_menu.',   // the dropdown holding the Settings link
        'settings.',       // the settings page, its form, and the language options
        'ui.aria.',        // the accessible names on that path
    ];

    /** Novelty catalogues, which are partial by design and so need this guarantee. */
    private const NOVELTY_CATALOGUES = ['tlh'];

    public function testTheWayBackToEnglishIsNeverTranslated(): void
    {
        foreach (self::NOVELTY_CATALOGUES as $locale) {
            $path = \dirname(__DIR__, 2).'/translations/messages.'.$locale.'.po';
            self::assertFileExists($path);

            preg_match_all('/^msgid "([^"]+)"/m', (string) file_get_contents($path), $matches);

            $offenders = array_values(array_filter($matches[1], static function (string $key): bool {
                foreach (self::PROTECTED_PREFIXES as $prefix) {
                    if (str_starts_with($key, $prefix)) {
                        return true;
                    }
                }

                return false;
            }));

            self::assertSame([], $offenders, sprintf(
                "messages.%s.po translates the escape path, which locks users into a language they may not read.\nRemove: %s",
                $locale,
                implode(', ', $offenders),
            ));
        }
    }

    /**
     * A three-letter language subtag must survive normalisation. Taking the first two characters
     * turned "tlh" into "tl", which matched nothing, so the locale silently never activated.
     */
    public function testAThreeLetterLanguageSubtagResolves(): void
    {
        $normalize = new \ReflectionMethod(LocaleSubscriber::class, 'normalize');

        $subscriber = new LocaleSubscriber(
            $this->createStub(\Symfony\Bundle\SecurityBundle\Security::class),
            $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class),
            ['en', 'de', 'tlh'],
        );

        self::assertSame('tlh', $normalize->invoke($subscriber, 'tlh'));
        self::assertSame('tlh', $normalize->invoke($subscriber, 'tlh_Latn'));
        self::assertSame('en', $normalize->invoke($subscriber, 'en_US'));
        self::assertSame('de', $normalize->invoke($subscriber, 'de-DE'));
        self::assertNull($normalize->invoke($subscriber, 'fr'));
        self::assertNull($normalize->invoke($subscriber, ''));
    }

    /** The novelty catalogue must never claim coverage it does not have. */
    public function testTheKlingonCatalogueStaysPartial(): void
    {
        $tlh = \dirname(__DIR__, 2).'/translations/messages.tlh.po';
        $en = \dirname(__DIR__, 2).'/translations/messages.en.po';

        preg_match_all('/^msgid "([^"]+)"/m', (string) file_get_contents($tlh), $tlhKeys);
        preg_match_all('/^msgid "([^"]+)"/m', (string) file_get_contents($en), $enKeys);

        self::assertNotEmpty($tlhKeys[1]);
        self::assertLessThan(
            \count($enKeys[1]) / 2,
            \count($tlhKeys[1]),
            'a near-complete Klingon catalogue would be mostly invented vocabulary; keep it curated',
        );

        // Everything it does translate must be a real key, or it silently translates nothing.
        self::assertSame([], array_values(array_diff($tlhKeys[1], $enKeys[1])), 'unknown keys in the Klingon catalogue');
    }
}
