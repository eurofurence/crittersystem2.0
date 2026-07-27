import { defineConfig } from 'vitest/config';

/*
 * Executes the Stimulus controllers in a DOM, which the PHPUnit suite cannot do: it renders markup
 * and never runs a line of the JavaScript that drives it. Two shipped defects came from exactly that
 * gap - a controller reading state before Stimulus had set it up, and a handler clearing state it
 * should have kept.
 *
 * This is test tooling only. AssetMapper still serves assets/ unbundled; nothing here may grow into
 * a build step for shipped JavaScript.
 */
export default defineConfig({
    test: {
        environment: 'happy-dom',
        include: ['tests/js/**/*.test.js'],
        // The importmap resolves bare specifiers in the browser; in tests they come from
        // node_modules, which is why @hotwired/stimulus is pinned to the importmap's version.
        globals: false,
    },
});
