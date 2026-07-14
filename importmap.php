<?php

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

    // Standalone Bootstrap 5 JS drives every `data-bs-*` component (modals, dropdowns,
    // toasts, navbar collapse) and is exposed as `window.bootstrap`. Tabler supplies the
    // visual layer (CSS) only: its JS bundle re-bundles Bootstrap and would double-initialise
    // the same `data-bs-*` components, so it must not be imported.
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
