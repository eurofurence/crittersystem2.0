import { Controller } from '@hotwired/stimulus';
import { backgroundFetch } from '../js/session.js';
import { confirmModal } from '../js/modal.js';

/*
 * The shift-group member picker: filter a department's shifts, tick the ones that belong together,
 * add them in one go.
 *
 * A department can hold a couple of hundred shifts that share a title and differ only by date, so the
 * list is filtered server-side and re-rendered in place. In place matters: the edit page carries the
 * group's name and description above this, and a filtering page reload would discard whatever the
 * manager had typed there but not saved.
 *
 * The selection is held here as a Set of shift uuids rather than read off the DOM, because the point
 * of filtering is to work across several views - tick two on Monday, filter to Tuesday, tick a third,
 * add all three. Ticks that scroll out of view are still part of the selection, so the count and the
 * "also selected elsewhere" note exist to make sure that can never be a silent surprise.
 */
export default class extends Controller {
    static targets = [
        'members', 'candidates', 'list', 'checkbox',
        'day', 'audience', 'type', 'query', 'past',
        'submit', 'clear', 'offscreen', 'error',
    ];

    static values = {
        candidatesUrl: String,
        membersUrl: String,
        addUrl: String,
        token: String,
        addLabel: String,
        offscreenLabel: String,
        failedMessage: String,
        debounce: { type: Number, default: 250 },
    };

    connect() {
        this.selected = new Set();
        this.timer = null;
        this.onListChange = this.onListChange.bind(this);
        this.candidatesTarget.addEventListener('change', this.onListChange);
        this.syncControls();
    }

    disconnect() {
        this.candidatesTarget.removeEventListener('change', this.onListChange);
        window.clearTimeout(this.timer);
    }

    /** Checkboxes are re-rendered on every refresh, so the listener lives on their container. */
    onListChange(event) {
        const box = event.target;
        if (!box.matches('input[type="checkbox"][value]')) {
            return;
        }
        if (box.checked) {
            this.selected.add(box.value);
        } else {
            this.selected.delete(box.value);
        }
        this.syncControls();
    }

    refresh() {
        window.clearTimeout(this.timer);
        this.load();
    }

    /** Typing in the search box should not fire a request per keystroke. */
    refreshLater() {
        window.clearTimeout(this.timer);
        this.timer = window.setTimeout(() => this.load(), this.debounceValue);
    }

    async load() {
        const url = new URL(this.candidatesUrlValue, window.location.origin);
        url.searchParams.set('day', this.dayTarget.value);
        url.searchParams.set('audience', this.audienceTarget.value);
        url.searchParams.set('type', this.typeTarget.value);
        url.searchParams.set('q', this.queryTarget.value.trim());
        url.searchParams.set('past', this.pastTarget.checked ? '1' : '0');

        try {
            const response = await backgroundFetch(url);
            if (response === null) {
                return;
            }
            this.candidatesTarget.innerHTML = await response.text();
            this.restoreChecks();
            this.syncControls();
            this.clearError();
        } catch (e) {
            console.error('Loading the shift list failed.', e);
            this.showError(this.failedMessageValue);
        }
    }

    /** Re-tick whatever is still selected after the list has been replaced. */
    restoreChecks() {
        this.candidatesTarget.querySelectorAll('input[type="checkbox"][value]').forEach((box) => {
            box.checked = this.selected.has(box.value);
        });
    }

    clear() {
        this.selected.clear();
        this.restoreChecks();
        this.syncControls();
    }

    syncControls() {
        const count = this.selected.size;
        this.submitTarget.textContent = this.addLabelValue.replace('__COUNT__', String(count));
        this.submitTarget.disabled = count === 0;
        this.clearTarget.hidden = count === 0;

        // Selected but not currently on screen: the manager is about to add shifts they cannot see,
        // so say how many rather than letting the count disagree with the list.
        const visible = Array.from(this.candidatesTarget.querySelectorAll('input[type="checkbox"][value]'))
            .filter((box) => this.selected.has(box.value)).length;
        const hidden = count - visible;
        this.offscreenTarget.hidden = hidden <= 0;
        this.offscreenTarget.textContent = hidden > 0
            ? this.offscreenLabelValue.replace('__COUNT__', String(hidden))
            : '';
    }

    async add() {
        if (this.selected.size === 0) {
            return;
        }

        const body = new FormData();
        body.append('_token', this.tokenValue);
        this.selected.forEach((id) => body.append('shifts[]', id));

        this.submitTarget.disabled = true;
        try {
            let data = await this.post(body);
            if (data === null) {
                return;
            }

            // Adding to a group that already has volunteers on it would leave some of them on part of
            // a commitment. Asked before anything is written, and answering yes replays the same
            // request against the state the count was taken from.
            if (data.confirm) {
                if (!(await confirmModal(data.confirm))) {
                    return;
                }
                body.append('confirm', '1');
                data = await this.post(body);
                if (data === null) {
                    return;
                }
            }

            if (data.ok !== true) {
                this.showError(data.error || this.failedMessageValue);
                return;
            }

            this.selected.clear();
            this.clearError();
            await Promise.all([this.reloadMembers(), this.load()]);
        } catch (e) {
            console.error('Adding shifts to the group failed.', e);
            this.showError(this.failedMessageValue);
        } finally {
            this.syncControls();
        }
    }

    async reloadMembers() {
        const response = await backgroundFetch(this.membersUrlValue);
        if (response === null) {
            return;
        }
        this.membersTarget.innerHTML = await response.text();
    }

    /** Returns the parsed body, or null when the session handler already took over the response. */
    async post(body) {
        const response = await backgroundFetch(this.addUrlValue, { method: 'POST', body });
        if (response === null) {
            return null;
        }

        return await response.json().catch(() => ({ ok: false }));
    }

    showError(message) {
        this.errorTarget.textContent = message || '';
        this.errorTarget.hidden = !message;
    }

    clearError() {
        this.showError('');
    }
}
