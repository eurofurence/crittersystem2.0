import { Controller } from '@hotwired/stimulus';
import { backgroundFetch } from '../js/session.js';
import { confirmModal, alertModal } from '../js/modal.js';

/*
 * Submits a planner form (Add Shift modal, single-shift edit, batch edit) over
 * fetch instead of a full navigation, then asks the planner to reload just the
 * grid. Optionally closes a Bootstrap modal on success.
 */
export default class extends Controller {
    /*
     * `reload` is for a change the grid reload cannot show. `planner:changed` refreshes the grid
     * only, so something that alters the toolbar itself - a new shift task appearing in the task
     * picker - needs the page.
     */
    static values = { closeModal: String, confirm: String, reload: Boolean };

    async submit(event) {
        event.preventDefault();
        const form = this.element;
        /*
         * These forms are submitted from a button's click, not the form's submit event, so the
         * browser never runs its own constraint checking - a required field like the shift task
         * would post empty and come back as a server error instead of pointing at the field.
         */
        if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
            return;
        }
        // Destructive or assignment-affecting edits confirm first.
        if (this.confirmValue && !(await confirmModal(this.confirmValue))) {
            return;
        }
        const button = form.querySelector('[type="submit"]');
        if (button) {
            button.disabled = true;
        }

        try {
            const body = new FormData(form);
            let result = await this.post(form.action, body);
            if (result === null) {
                return;
            }

            /*
             * The server can ask a question before it writes anything: batching shifts into a shift
             * group that already has volunteers on it would leave some of them on part of a
             * commitment, which is the same confirmation the management screen requires. Answering
             * yes replays the identical request with the flag set, so the decision is made against
             * the state the count was taken from.
             */
            if (result.data.confirm) {
                if (!(await confirmModal(result.data.confirm))) {
                    return;
                }
                body.append('confirm', '1');
                result = await this.post(form.action, body);
                if (result === null) {
                    return;
                }
            }

            const { response, data } = result;
            if (!response.ok || data.ok === false) {
                this.markInvalid(data.invalid);
                await alertModal(data.error || (data.errors ? data.errors.join('\n') : 'The change could not be saved.'));
                return;
            }
            if (data.published !== undefined) {
                this.markInvalid([]);
            }
            if (Array.isArray(data.warnings) && data.warnings.length > 0) {
                await alertModal('Published with warnings:\n' + data.warnings.join('\n'));
            }
            this.closeModalIfAny();
            if (this.reloadValue) {
                window.location.reload();
                return;
            }
            window.dispatchEvent(new CustomEvent('planner:changed'));
        } catch (e) {
            console.error('Save request failed.', e);
            await alertModal('Network error while saving.');
        } finally {
            if (button) {
                button.disabled = false;
            }
        }
    }

    /** Returns {response, data}, or null when the session handler already took over the response. */
    async post(action, body) {
        const response = await backgroundFetch(action, { method: 'POST', body });
        if (response === null) {
            return null;
        }

        return { response, data: await response.json().catch(() => ({})) };
    }

    markInvalid(uuids) {
        if (!Array.isArray(uuids)) {
            return;
        }
        window.dispatchEvent(new CustomEvent('planner:invalid', { detail: { uuids } }));
    }

    closeModalIfAny() {
        if (!this.closeModalValue) {
            return;
        }
        const modalEl = document.getElementById(this.closeModalValue);
        if (modalEl && window.bootstrap) {
            window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        }
    }
}
