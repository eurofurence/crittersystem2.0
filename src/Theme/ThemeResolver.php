<?php

namespace App\Theme;

use App\Entity\User;
use App\Service\EventConfigStore;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves the active theme for the current request using the priority chain
 * (legacy parity, simplified):
 *
 *   1. `?theme=<slug>` query parameter (demo / preview, only when the slug is known)
 *   2. The authenticated user's `Settings.theme` slug
 *   3. The admin-set default in `EventConfigStore` (`theme.default`)
 *   4. The first theme in {@see ThemeCatalog} (always a valid fallback)
 */
final class ThemeResolver
{
    public function __construct(
        private readonly ThemeCatalog $catalog,
        private readonly EventConfigStore $config,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function resolve(): Theme
    {
        $request = $this->requestStack->getMainRequest();
        if ($request !== null) {
            $queried = (string) $request->query->get('theme', '');
            if ($queried !== '' && ($theme = $this->catalog->find($queried)) !== null) {
                return $theme;
            }
        }

        // The remaining lookups touch the database (current user, event config).
        // This global runs on *every* template render, including the maintenance
        // and install pages that are shown precisely when the database may be
        // unreachable or unmigrated — so a failure here must degrade to the
        // fallback theme rather than turning every page into a 500.
        try {
            $user = $this->security->getUser();
            if ($user instanceof User
                && ($slug = $user->getSettings()?->getTheme()) !== null
                && ($theme = $this->catalog->find($slug)) !== null
            ) {
                return $theme;
            }

            $defaultSlug = (string) $this->config->get(EventConfigStore::KEY_DEFAULT_THEME, '');
            if ($defaultSlug !== '' && ($theme = $this->catalog->find($defaultSlug)) !== null) {
                return $theme;
            }
        } catch (\Throwable) {
            // Database not available/migrated yet — fall through to the fallback.
        }

        return $this->catalog->fallback();
    }
}
