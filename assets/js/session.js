/*
 * Session-expiry handling for every request the page makes in the background.
 *
 * The server answers a background request from an expired session with 401 (see
 * App\Security\LoginFormAuthenticator). It must never answer with the login page: `fetch` follows
 * redirects, so a redirected poll comes back as 200 OK carrying the whole login document, and a caller
 * that only checks `response.ok` will happily inject that document into the page.
 *
 * When the session is gone the page is dead: it is still displaying the previous user's personal data.
 * So we do not try to recover it — we clear it and leave, keeping the current path so the user lands
 * back where they were once they sign in again.
 */

let leaving = false;

/** True when the response says the session is gone. */
export function isSessionExpired(response) {
    // The 401 the entry point returns. `redirected` catches anything that still lands on the login page
    // (an endpoint outside the firewall's entry point, a proxy, a future refactor).
    return response.status === 401
        || (response.redirected && new URL(response.url, window.location.origin).pathname === '/login');
}

/**
 * Leave for the login page, carrying the page the user was on.
 *
 * Idempotent: the status widget and the notification bell poll on the same tick, so both will call this.
 * The body is emptied before navigating — a navigation is not instant, and the point of leaving is that
 * the data on screen should stop being on screen.
 */
export function sessionExpired() {
    if (leaving) {
        return;
    }
    leaving = true;

    document.body.innerHTML = '';

    const here = window.location.pathname + window.location.search;
    window.location.assign(`/login?return=${encodeURIComponent(here)}`);
}

/**
 * `fetch` for anything the page requests on its own behalf.
 *
 * The X-Requested-With header is not decoration. It is what tells the server this is not a navigation,
 * so it answers 401 rather than redirecting — and it is also what stops Symfony recording the polled URL
 * as the place to return to after signing in, which would strand the user on a bare `/status` fragment
 * instead of the page they were reading.
 *
 * Returns null when the session has expired (the caller is already navigating away, so it should stop).
 */
export async function backgroundFetch(url, options = {}) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            ...(options.headers || {}),
        },
    });

    if (isSessionExpired(response)) {
        sessionExpired();

        return null;
    }

    return response;
}

/** Turbo makes its own requests (Drive visits, frame loads); route their 401s to the same place. */
export function watchTurboForSessionExpiry() {
    document.addEventListener('turbo:before-fetch-response', (event) => {
        if (event.detail?.fetchResponse?.statusCode === 401) {
            event.preventDefault();
            sessionExpired();
        }
    });
}
