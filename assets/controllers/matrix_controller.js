import { Controller } from '@hotwired/stimulus';
import { backgroundFetch } from '../js/session.js';
import { confirmModal, alertModal } from '../js/modal.js';

/*
 * Advanced Matrix Planner interaction.
 *
 * Two jobs:
 *
 *  1. Keep the page current. Every mutation here - including the structure forms, which use the
 *     shared `planner-form` controller - ends in a `planner:changed` event. Reloading the content
 *     region on that event is what keeps newly added groups, positions and assignments visible
 *     without a manual page refresh. The region covers the structure panel too, so a new group also
 *     appears in the "Add Named Position" dropdown.
 *
 *  2. Edit a cell. Clicking a cell opens the editor, which drives the existing endpoints: enable a
 *     position on a shift, toggle required, set a note, assign a volunteer, unassign one, or disable
 *     the position again.
 *
 * The server owns every rule (department scope, `shift:assign`, capacity, CSRF). This controller
 * only asks; a rejection comes back as JSON and is shown as-is.
 */
export default class extends Controller {
    static targets = ['content', 'editor', 'editorTitle', 'editorBody'];
    static values = {
        token: String,
        enableUrl: String,
        assignUrl: String,
        unassignUrl: String,
        requiredUrl: String,
        noteUrl: String,
        disableUrl: String,
        canAssign: Boolean,
    };

    connect() {
        this.onChanged = () => this.reloadContent();
        this.onRemoteChanged = () => this.queueRemoteReload();
        this.onEditMaybeFinished = () => this.applyRemoteReloadIfIdle();

        window.addEventListener('planner:changed', this.onChanged);
        // Another manager edited this department. Held back while the cell editor is open.
        window.addEventListener('planner:remote-changed', this.onRemoteChanged);
        document.addEventListener('hidden.bs.modal', this.onEditMaybeFinished);
    }

    disconnect() {
        window.removeEventListener('planner:changed', this.onChanged);
        window.removeEventListener('planner:remote-changed', this.onRemoteChanged);
        document.removeEventListener('hidden.bs.modal', this.onEditMaybeFinished);
    }

    /**
     * Someone else changed this department.
     *
     * Not applied on arrival: reloadContent() replaces the whole content region, and the cell editor
     * is populated from the cell that was clicked, so swapping the grid underneath an open editor
     * leaves it acting on a cell that no longer exists in the page. The change waits until the
     * editor is closed.
     *
     * A manager's own edits still apply immediately - they arrive as planner:changed, and the editor
     * closes itself as part of saving.
     */
    queueRemoteReload() {
        this.remotePending = true;
        this.applyRemoteReloadIfIdle();
    }

    applyRemoteReloadIfIdle() {
        if (!this.remotePending || document.querySelector('.modal.show') !== null) {
            return;
        }
        this.remotePending = false;
        this.reloadContent();
    }

    // ---- cell editor ------------------------------------------------------

    openCell(event) {
        const cell = event.currentTarget;
        this.cell = { ...cell.dataset };
        this.editorTitleTarget.textContent = `${this.cell.position} - ${this.cell.shiftTitle}`;
        this.editorBodyTarget.innerHTML = this.renderEditor(this.cell);
        this.mountPicker();
        this.modal().show();
    }

    // Clone the shared user-select type-ahead into the editor. Cloning (rather than rebuilding its
    // markup here) keeps the picker identical to the one on the staffing screen.
    mountPicker() {
        const placeholder = this.editorBodyTarget.querySelector('.matrix-user-picker');
        const template = document.getElementById('matrix-user-picker');
        if (placeholder && template) {
            placeholder.appendChild(template.content.cloneNode(true));
        }
    }

    renderEditor(cell) {
        const assignments = JSON.parse(cell.assignments || '[]');
        const enabled = cell.shiftPositionUuid !== '';

        if (!enabled) {
            return `
                <p class="text-secondary">This position is not enabled on this shift.</p>
                <button class="btn btn-primary" data-action="matrix#enable">Enable position</button>`;
        }

        const occupants = assignments.length
            ? assignments.map((a) => `
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span>${escapeHtml(a.name)}</span>
                    ${this.canAssignValue
                        ? `<button class="btn btn-sm btn-outline-danger" data-action="matrix#unassign" data-assignment="${a.uuid}">Remove</button>`
                        : ''}
                </li>`).join('')
            : '<li class="list-group-item text-secondary">Nobody assigned yet.</li>';

        const full = assignments.length >= Number(cell.capacity);
        let assignBlock;
        if (!this.canAssignValue) {
            assignBlock = '<p class="text-secondary mb-0">You do not have permission to assign volunteers.</p>';
        } else if (full) {
            assignBlock = `<p class="text-secondary mb-0">This position is full (${cell.capacity}/${cell.capacity}).</p>`;
        } else {
            // The type-ahead is cloned in by mountPicker(); its picked users submit as matrix_user[].
            assignBlock = `
                <label class="form-label">Assign a volunteer</label>
                <div class="matrix-user-picker"></div>
                <button class="btn btn-primary" data-action="matrix#assign">Assign</button>`;
        }

        return `
            <ul class="list-group mb-3">${occupants}</ul>
            <div class="mb-3">${assignBlock}</div>
            <hr>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="matrix-required"
                       data-matrix-role="required" data-action="matrix#toggleRequired"
                       ${cell.required === '1' ? 'checked' : ''}>
                <label class="form-check-label" for="matrix-required">Required for this shift</label>
            </div>
            <div class="mb-3">
                <label class="form-label" for="matrix-note">Note</label>
                <div class="input-group">
                    <input type="text" class="form-control" id="matrix-note" data-matrix-role="note"
                           value="${escapeHtml(cell.note || '')}">
                    <button class="btn btn-outline-secondary" data-action="matrix#saveNote">Save</button>
                </div>
            </div>
            <button class="btn btn-outline-danger" data-action="matrix#disable">Disable position on this shift</button>`;
    }

    // ---- mutations --------------------------------------------------------

    enable() {
        this.post(
            this.enableUrlValue
                .replace('__SHIFT__', this.cell.shift)
                .replace('__POSITION__', this.cell.positionUuid),
            { required: '1' },
        );
    }

    async assign() {
        const ids = Array.from(this.editorBodyTarget.querySelectorAll('input[name="matrix_user[]"]'))
            .map((input) => input.value)
            .filter(Boolean);
        if (ids.length === 0) {
            return;
        }

        // Place each picked user in turn; the server enforces capacity and rejects duplicates, so a
        // partial batch reports what could not be placed without losing what was.
        const url = this.assignUrlValue.replace('__ID__', this.cell.shiftPositionUuid);
        let assigned = 0;
        const errors = [];
        for (const id of ids) {
            const result = await this.request(url, { user: id });
            if (result.ok) {
                assigned += 1;
            } else if (result.error) {
                errors.push(result.error);
            }
        }

        if (assigned > 0) {
            this.modal().hide();
            window.dispatchEvent(new CustomEvent('planner:changed'));
        }
        if (errors.length > 0) {
            await alertModal([...new Set(errors)].join(' '));
        }
    }

    unassign(event) {
        this.post(this.unassignUrlValue.replace('__ID__', event.currentTarget.dataset.assignment), {});
    }

    toggleRequired(event) {
        this.post(this.requiredUrlValue.replace('__ID__', this.cell.shiftPositionUuid), {
            required: event.currentTarget.checked ? '1' : '0',
        });
    }

    saveNote() {
        const input = this.editorBodyTarget.querySelector('[data-matrix-role="note"]');
        this.post(this.noteUrlValue.replace('__ID__', this.cell.shiftPositionUuid), { note: input ? input.value : '' });
    }

    async disable() {
        if (!(await confirmModal('Disable this position on this shift? Any assignments on it are removed.', { variant: 'danger' }))) {
            return;
        }
        // `force` tells the server to drop existing assignments; without it the request is refused
        // when the position is occupied.
        this.post(this.disableUrlValue.replace('__ID__', this.cell.shiftPositionUuid), { force: '1' });
    }

    async post(url, body) {
        const result = await this.request(url, body);
        if (result.skipped) {
            return;
        }
        if (!result.ok) {
            await alertModal(result.error);
            return;
        }
        this.modal().hide();
        window.dispatchEvent(new CustomEvent('planner:changed'));
    }

    /** POST a mutation and report its outcome without touching the modal, so callers can batch. */
    async request(url, body) {
        const form = new FormData();
        form.append('_token', this.tokenValue);
        Object.entries(body).forEach(([key, value]) => form.append(key, value));

        try {
            const response = await backgroundFetch(url, { method: 'POST', body: form });
            if (response === null) {
                return { skipped: true };
            }
            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.ok === false) {
                return { ok: false, error: data.error || 'The change could not be saved.' };
            }
            return { ok: true };
        } catch (e) {
            console.error('Save request failed.', e);
            return { ok: false, error: 'Network error while saving.' };
        }
    }

    // ---- refresh ----------------------------------------------------------

    async reloadContent() {
        try {
            const response = await backgroundFetch(window.location.href);
            if (response === null) {
                return;
            }
            const html = await response.text();
            const parsed = new DOMParser().parseFromString(html, 'text/html');
            const fresh = parsed.querySelector('[data-matrix-target="content"]');
            if (fresh && this.hasContentTarget) {
                this.contentTarget.replaceWith(fresh);
                return;
            }
            window.location.reload();
        } catch (e) {
            // A bug in the swap above would otherwise surface only as the page mysteriously reloading -
            // and, if it throws again on the way back, as a reload loop with an empty console.
            console.error('Refresh failed; falling back to a full page load.', e);
            window.location.reload();
        }
    }

    modal() {
        return window.bootstrap.Modal.getOrCreateInstance(this.editorTarget);
    }
}

/* The editor is built as a string, so every value that came from the database is escaped here. */
function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value;
    return div.innerHTML;
}
