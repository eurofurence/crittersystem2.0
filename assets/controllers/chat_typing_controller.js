import { Controller } from '@hotwired/stimulus';
import { backgroundFetch } from '../js/session.js';

/*
 * Sends a throttled "typing" ping while the user types in a chat input. The ping is what makes the
 * other participants' thread show the indicator: recording it publishes a change on the
 * conversation, and their thread refreshes.
 *
 * The 3s throttle is load-bearing now that a ping reaches every reader of the thread. Unthrottled,
 * one person typing would refresh everyone else's thread on every keystroke.
 */
export default class extends Controller {
    static values = { url: String };

    connect() {
        this.last = 0;
    }

    ping() {
        const now = Date.now();
        if (now - this.last < 3000) {
            return;
        }
        this.last = now;
        backgroundFetch(this.urlValue, { method: 'POST' }).catch(() => {});
    }
}
