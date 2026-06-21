<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
    '@symfony/stimulus-bundle' => [
        'path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js',
    ],
    '@hotwired/turbo' => [
        'version' => '8.0.20',
    ],

    /**
     * !! CRITICAL !!
     * Bootstrap 5 JS only — drives every `data-bs-*` component (modals,
     * dropdowns, toasts, navbar collapse) and is exposed as `window.bootstrap`.
     * The visual layer comes from Tabler's CSS below;
     * We intentionally do NOT import Tabler's JS bundle because it
     * re-bundles Bootstrap and would double-initialise
     * the same `data-bs-*` components.
     * 
     */
    'bootstrap' => [
        'version' => '5.3.8',
    ],
    '@popperjs/core' => [
        'version' => '2.11.8',
    ],
    // Tabler 1.4 design system (a Bootstrap 5 theme). Replaces stock Bootstrap CSS.
    '@tabler/core/dist/css/tabler.min.css' => [
        'version' => '1.4.0',
        'type' => 'css',
    ],
];
