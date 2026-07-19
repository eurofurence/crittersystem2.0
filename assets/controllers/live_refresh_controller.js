import { Controller } from '@hotwired/stimulus';
import { backgroundFetch } from '../js/session.js';

/*
 * Reusable near-real-time polling. Two modes, chosen by the values you set:
 *
 *   Frame mode  - data-live-refresh-frame-value="some_frame_id"
 *                 reloads that <turbo-frame> on each tick (optionally repointing
 *                 its src to data-live-refresh-url-value first).
 *   Fetch mode  - data-live-refresh-url-value="/path"
 *                 fetches the URL and replaces this element's innerHTML; a 304
 *                 response is treated as "no change" and left untouched.
 *
 * Polling pauses while the tab is hidden and resumes (with an immediate tick) on
 * return, and is fully torn down on disconnect.
 */
export default class extends Controller {
    static values = {
        interval: { type: Number, default: 15000 },
        url: String,
        frame: String,
    };

    connect() {
        this.onVisibility = this.handleVisibility.bind(this);
        document.addEventListener('visibilitychange', this.onVisibility);
        this.start();
    }

    disconnect() {
        this.stop();
        document.removeEventListener('visibilitychange', this.onVisibility);
    }

    start() {
        if (this.timer || document.hidden) {
            return;
        }
        this.timer = window.setInterval(() => this.tick(), this.intervalValue);
    }

    stop() {
        if (this.timer) {
            window.clearInterval(this.timer);
            this.timer = null;
        }
    }

    handleVisibility() {
        if (document.hidden) {
            this.stop();
        } else {
            this.tick();
            this.start();
        }
    }

    async tick() {
        if (document.hidden) {
            return;
        }

        if (this.frameValue) {
            const frame = document.getElementById(this.frameValue);
            if (frame) {
                if (this.hasUrlValue) {
                    frame.setAttribute('src', this.urlValue);
                } else if (typeof frame.reload === 'function') {
                    frame.reload();
                }
            }
            return;
        }

        if (this.hasUrlValue) {
            try {
                const response = await backgroundFetch(this.urlValue, { headers: { Accept: 'text/html' } });

                // Session gone: we are already navigating away, so stop polling and touch nothing.
                if (response === null) {
                    this.stop();

                    return;
                }
                if (response.status === 304 || !response.ok) {
                    return;
                }
                this.element.innerHTML = await response.text();
            } catch (error) {
                /* transient network error - ignore and retry on the next tick */
            }
        }
    }
}
