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
    'bootstrap' => [
        'version' => '5.3.8',
    ],
    '@popperjs/core' => [
        'version' => '2.11.8',
    ],
    '@tabler/core/dist/css/tabler.min.css' => [
        'version' => '1.4.0',
        'type' => 'css',
    ],
    '@sentry/browser' => [
        'version' => '10.67.0',
    ],
    '@sentry/feedback' => [
        'version' => '10.67.0',
    ],
    '@sentry/core/browser' => [
        'version' => '10.67.0',
    ],
    '@sentry/browser-utils' => [
        'version' => '10.67.0',
    ],
    '@sentry/conventions/attributes' => [
        'version' => '0.16.0',
    ],
    '@sentry/replay' => [
        'version' => '10.67.0',
    ],
    '@sentry/replay-canvas' => [
        'version' => '10.67.0',
    ],
    '@sentry/core' => [
        'version' => '10.67.0',
    ],
];
