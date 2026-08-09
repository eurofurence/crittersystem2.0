import { Controller } from '@hotwired/stimulus';
import { alertModal } from '../js/modal.js';
import { backgroundFetch } from '../js/session.js';

/*
 * The staff shift application grid.
 *
 * Clicking a shift opens a dialog the server renders: what the volunteer may do with that shift,
 * and every reason they may not, is decided under their own authorization rather than assembled
 * from anything the page is holding.
 *
 * A change in one of the departments on screen refreshes the grid, but never while the dialog is
 * open: replacing it underneath would close the shift the volunteer was reading.
 */
export default class extends Controller {
    static targets = ['grid', 'dialog', 'filters'];
    static values = { detailUrl: String, gridUrl: String };

    connect() {
        this.onRemoteChange = () => this.queueRefresh();
        this.onDialogClosed = () => this.applyRefreshIfIdle();
        window.addEventListener('apply-grid:changed', this.onRemoteChange);
        document.addEventListener('hidden.bs.modal', this.onDialogClosed);
    }

    disconnect() {
        clearTimeout(this.refreshTimer);
        window.removeEventListener('apply-grid:changed', this.onRemoteChange);
        document.removeEventListener('hidden.bs.modal', this.onDialogClosed);
    }

    submitFilters() {
        this.filtersTarget.requestSubmit();
    }

    async open(event) {
        const uuid = event.params.shift;
        if (!uuid || !this.hasDialogTarget) {
            return;
        }

        this.dialogTarget.innerHTML = '<div class="modal-body text-secondary">…</div>';
        this.show();

        try {
            // The filters travel with the request: the dialog builds its apply and cancel actions
            // from them, and without them applying would land the volunteer back on the default day
            // instead of the one they were looking at.
            const url = this.detailUrlValue.replace('__ID__', uuid) + window.location.search;
            const response = await backgroundFetch(url);
            if (response === null) {
                return;
            }
            if (!response.ok) {
                this.hide();
                await alertModal('This shift is no longer available.');

                return;
            }
            this.dialogTarget.innerHTML = await response.text();
        } catch (e) {
            console.error('Could not load the shift.', e);
            this.dialogTarget.innerHTML = '<div class="modal-body text-danger">The shift could not be loaded.</div>';
        }
    }

    modal() {
        const element = document.querySelector('#apply-shift-modal');

        return element && window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(element) : null;
    }

    show() {
        this.modal()?.show();
    }

    hide() {
        this.modal()?.hide();
    }

    /*
     * One change can announce itself twice: an all-staff shift wakes both its own department and
     * the all-staff topic, and a viewer who is a member of that department listens to both. The
     * refresh is coalesced so that costs one request rather than two.
     */
    queueRefresh() {
        this.pending = true;
        clearTimeout(this.refreshTimer);
        this.refreshTimer = setTimeout(() => this.applyRefreshIfIdle(), 50);
    }

    applyRefreshIfIdle() {
        if (!this.pending || document.querySelector('.modal.show') !== null) {
            return;
        }
        this.pending = false;
        this.refresh();
    }

    /**
     * The grid is re-read with the filters that are on the address bar, so a refresh cannot quietly
     * change the day or the departments the volunteer is looking at.
     */
    async refresh() {
        try {
            const url = this.gridUrlValue + window.location.search;
            const response = await backgroundFetch(url, { headers: { Accept: 'text/html' } });
            if (response === null || !response.ok) {
                return;
            }
            const html = await response.text();
            if (/<html[\s>]/i.test(html)) {
                console.warn('Apply grid received a full document instead of a fragment; ignoring.');

                return;
            }
            const scroller = this.gridTarget.querySelector('.apply-grid');
            const left = scroller?.scrollLeft ?? 0;
            const top = scroller?.scrollTop ?? 0;

            this.gridTarget.innerHTML = html;

            const fresh = this.gridTarget.querySelector('.apply-grid');
            if (fresh) {
                fresh.scrollLeft = left;
                fresh.scrollTop = top;
            }
        } catch (e) {
            console.error('The grid could not be refreshed.', e);
        }
    }
}
