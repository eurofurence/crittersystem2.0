<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Resolves the active UI locale for each request.
 *
 * Precedence: an explicit `?_locale=` override (remembered in the session) → the signed-in user's
 * stored language preference → a previously remembered session locale → the framework default.
 *
 * Stored preferences use the `xx_YY` form (`en_US`, `de_DE`) while the translator and `.po` catalogs
 * use the short code (`en`, `de`); this maps between them and only accepts an enabled locale.
 *
 * Runs after the firewall so the authenticated user is available, and sets the translator locale
 * directly rather than relying on listener ordering.
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    /** @var list<string> */
    private array $enabledLocales;

    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        array $enabledLocales = ['en', 'de', 'tlh'],
    ) {
        $this->enabledLocales = $enabledLocales;
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 6: after the firewall (8) so the user is resolved, before the kernel syncs
        // locale-aware services from the request.
        return [KernelEvents::REQUEST => [['onKernelRequest', 6]]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $locale = $this->resolveLocale($request);
        if ($locale === null) {
            return;
        }

        $request->setLocale($locale);
        if ($this->translator instanceof LocaleAwareInterface) {
            $this->translator->setLocale($locale);
        }
    }

    private function resolveLocale(RequestEvent|\Symfony\Component\HttpFoundation\Request $request): ?string
    {
        if ($request instanceof RequestEvent) {
            $request = $request->getRequest();
        }

        $override = $this->normalize($request->query->get('_locale'));
        if ($override !== null) {
            if ($request->hasSession()) {
                $request->getSession()->set('_locale', $override);
            }

            return $override;
        }

        $user = $this->security->getUser();
        if ($user instanceof User) {
            $preferred = $this->normalize($user->getSettings()?->getLanguage());
            if ($preferred !== null) {
                return $preferred;
            }
        }

        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $stored = $this->normalize($request->getSession()->get('_locale'));
            if ($stored !== null) {
                return $stored;
            }
        }

        return null;
    }

    /**
     * Map a stored/query locale ("en_US", "de", "de_DE", "tlh") to a supported code, or null.
     *
     * Takes the language subtag rather than the first two characters: a three-letter ISO 639-2
     * code such as "tlh" (Klingon) would otherwise be truncated to "tl" and match nothing, so the
     * locale could never activate.
     */
    private function normalize(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $language = strtolower(strtok(str_replace('-', '_', $value), '_') ?: '');

        return in_array($language, $this->enabledLocales, true) ? $language : null;
    }
}
