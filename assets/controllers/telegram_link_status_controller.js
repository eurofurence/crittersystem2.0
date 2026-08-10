import { Controller } from '@hotwired/stimulus';
import { backgroundFetch, isSessionExpired, sessionExpired } from '../js/session.js';

/*
 * Watches for the Telegram account link to be confirmed by the bot, and reloads the page once it is.
 *
 * This replaces an inline script whose setTimeout rescheduled itself. Turbo swaps the body without
 * tearing the document down, so that loop outlived the page it belonged to and a fresh one started
 * on every visit: volunteers who opened the link step and then browsed on kept hitting the status
 * endpoint every three seconds, several loops at a time, for as long as the tab stayed open.
 *
 * Everything here exists to bound that:
 *  - disconnect() clears the timer, which Turbo does call, so the poll dies with the page;
 *  - polling stops for good once the pending code has expired, because no bot confirmation can
 *    arrive against a code the server will no longer accept;
 *  - a hidden tab is not polled at all, and resumes on the next visibility change.
 */
export default class extends Controller {
    static values = {
        url: String,
        interval: { type: Number, default: 3000 },
        errorInterval: { type: Number, default: 5000 },
        // ISO-8601 instant the pending link code stops being accepted; empty means no known expiry.
        expiresAt: String,
    };

    connect() {
        this.stopped = false;
        this.timer = null;
        this.onVisibilityChange = () => {
            if (document.hidden) {
                this.clearTimer();
            } else if (!this.stopped) {
                this.schedule(0);
            }
        };
        document.addEventListener('visibilitychange', this.onVisibilityChange);
        this.schedule(0);
    }

    disconnect() {
        this.stopped = true;
        this.clearTimer();
        document.removeEventListener('visibilitychange', this.onVisibilityChange);
    }

    clearTimer() {
        if (this.timer !== null) {
            window.clearTimeout(this.timer);
            this.timer = null;
        }
    }

    schedule(delay) {
        this.clearTimer();
        if (this.stopped || document.hidden) {
            return;
        }
        this.timer = window.setTimeout(() => this.check(), delay);
    }

    expired() {
        if (!this.expiresAtValue) {
            return false;
        }
        const deadline = Date.parse(this.expiresAtValue);

        return !Number.isNaN(deadline) && Date.now() >= deadline;
    }

    async check() {
        this.timer = null;
        if (this.stopped) {
            return;
        }
        if (this.expired()) {
            this.stopped = true;

            return;
        }

        try {
            const response = await backgroundFetch(this.urlValue);
            if (isSessionExpired(response)) {
                this.stopped = true;
                sessionExpired();

                return;
            }
            const data = await response.json();
            if (data.linked) {
                this.stopped = true;
                window.location.reload();

                return;
            }
            this.schedule(this.intervalValue);
        } catch {
            this.schedule(this.errorIntervalValue);
        }
    }
}
