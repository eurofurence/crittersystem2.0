<?php

namespace App\Theme;

/**
 * Single source of truth for the application's themes. Each theme has a stable
 * slug (stored in user settings + event config), a display name, a `type`
 * (light|dark — drives the Bootstrap 5.3 `data-bs-theme` attribute) and an
 * asset path resolved through AssetMapper.
 *
 * **Adding a theme:** drop a CSS file under `assets/themes/`, add it here.
 * No compilation step is required for development — the file is served by
 * AssetMapper. For production builds, run `php bin/console asset-map:compile`
 * to produce hashed immutable copies under `public/assets/`.
 */
final class ThemeCatalog
{
    /** @var Theme[] */
    private array $themes;

    public function __construct()
    {
        $this->themes = [
            new Theme('default', 'Default', 'light', 'themes/default.css'),
            new Theme('dark', 'Dark', 'dark', 'themes/dark.css'),
            new Theme('eurofurence', 'Eurofurence', 'light', 'themes/eurofurence.css'),
        ];
    }

    /** @return Theme[] */
    public function all(): array
    {
        return $this->themes;
    }

    public function find(string $slug): ?Theme
    {
        foreach ($this->themes as $theme) {
            if ($theme->slug === $slug) {
                return $theme;
            }
        }

        return null;
    }

    public function has(string $slug): bool
    {
        return $this->find($slug) !== null;
    }

    public function fallback(): Theme
    {
        return $this->themes[0];
    }

    /**
     * For form select fields: ['slug' => 'Display Name'].
     *
     * @return array<string, string>
     */
    public function choices(): array
    {
        $choices = [];
        foreach ($this->themes as $theme) {
            $choices[$theme->name] = $theme->slug;
        }

        return $choices;
    }
}
