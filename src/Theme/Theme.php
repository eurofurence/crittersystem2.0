<?php

namespace App\Theme;

/**
 * A single theme: stable slug, display name, Bootstrap mode (light/dark, used
 * for the `data-bs-theme` attribute) and the AssetMapper path to its stylesheet.
 */
final readonly class Theme
{
    public function __construct(
        public string $slug,
        public string $name,
        public string $type,
        public string $assetPath,
    ) {
    }
}
