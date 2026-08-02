import { backgroundFetch } from './session.js';

/*
 * Keeps a page that is sitting still alive.
 *
 * The live transport is one connection to the hub, so it never touches this application. Two things
 * used to ride along on the polling it replaced: the session idle timer, which is what keeps an
 * unattended bounty-board display signed in for the length of an event, and the five-minute Mercure
 * subscriber token. This one request every five minutes carries both, in place of the fourteen a
 * minute the widgets used to make.
 *
 * It deliberately keeps running while the tab is hidden. A wall display is "hidden" by any screen
 * blanking, and letting it drop would sign the display out exactly when nobody is there to notice.
 */

const INTERVAL_MS = 300000;

let timer = null;

function url() {
    return document.querySelector('meta[name="heartbeat-url"]')?.content || '';
}

async function beat() {
    const target = url();
    if (target === '') {
        stop();

        return;
    }

    try {
        // backgroundFetch takes the page to the login screen on 401, which is what should happen
        // when the session really has gone.
        await backgroundFetch(target);
    } catch (error) {
        /* transient network error - the next beat tries again */
    }
}

export function start() {
    stop();
    if (url() === '') {
        return;
    }
    timer = window.setInterval(beat, INTERVAL_MS);
}

export function stop() {
    if (timer) {
        window.clearInterval(timer);
        timer = null;
    }
}

// Turbo replaces the document on every navigation, so the meta tag (and whether there is one at all)
// is re-read each time rather than captured once at load.
document.addEventListener('turbo:load', start);
