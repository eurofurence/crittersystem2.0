import { Controller } from '@hotwired/stimulus';
import { isDegraded } from '../js/live.js';

/*
 * The connection indicator in the board's rail.
 *
 * A wall display is watched from across a room and nobody is touching it, so "the numbers stopped
 * being true" has to be visible without interacting with the page. The state is written as a word as
 * well as a colour, because at that distance the dot alone is not a reliable signal.
 */
export default class extends Controller {
    static targets = ['label'];

    static values = {
        connected: String,
        reconnecting: String,
    };

    connect() {
        this.onState = (event) => this.apply(event.detail.state);
        window.addEventListener('live:state', this.onState);
        this.apply(isDegraded() ? 'degraded' : 'connected');
    }

    disconnect() {
        window.removeEventListener('live:state', this.onState);
    }

    apply(state) {
        this.element.dataset.state = state === 'degraded' ? 'degraded' : 'connected';

        if (this.hasLabelTarget) {
            this.labelTarget.textContent = state === 'degraded' ? this.reconnectingValue : this.connectedValue;
        }
    }
}
