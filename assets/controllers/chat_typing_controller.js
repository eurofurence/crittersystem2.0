import { Controller } from '@hotwired/stimulus';
import { backgroundFetch } from '../js/session.js';

/*
 * Sends a throttled "typing" ping while the user types in a chat input, so the
 * other participants' polled thread can show a typing indicator.
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
