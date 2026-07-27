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
        // Destructive or assignment-affecting edits confirm first.
        if (this.confirmValue && !(await confirmModal(this.confirmValue))) {
            return;
        }
        const form = this.element;
        const button = form.querySelector('[type="submit"]');
        if (button) {
            button.disabled = true;
        }

        try {
            const response = await backgroundFetch(form.action, {
                method: 'POST',
                body: new FormData(form),
            });
            if (response === null) {
                return;
            }
            const data = await response.json().catch(() => ({}));
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
