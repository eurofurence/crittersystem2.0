import { Controller } from '@hotwired/stimulus';
import { alertModal } from '../js/modal.js';

/*
 * Confirmation for shifts that can only be taken together.
 *
 * Applying to one member of a shift group signs the volunteer up for every member, so the submit is
 * intercepted and a modal shows exactly what is being committed to: each shift with its day, time,
 * location, role and capacity, the hours the group adds, and any role the volunteer still has to
 * pick. The same modal opens read-only from an info link, and in a cancel variant that lists what
 * cancelling would remove.
 *
 *   <form method="post" action="..."
 *         data-controller="shift-group"
 *         data-shift-group-url-value="{{ path('app_shift_group_modal', {id: shift.uuid}) }}"
 *         data-shift-group-title-value="..."
 *         data-shift-group-confirm-label-value="..."
 *         data-shift-group-mode-value="apply">
 *
 * The body is fetched from the server on every open, never built here from a data attribute:
 * capacity and eligibility are per viewer and move between page render and click, and the visibility
 * filter that keeps a shift the viewer may not see out of the list runs server-side.
 *
 * Without JavaScript the form still submits and the server still enforces the group. This is a
 * clarity layer, not the enforcement.
 */
export default class extends Controller {
    static values = {
        url: String,
        title: { type: String, default: 'These shifts go together' },
        confirmLabel: { type: String, default: 'Confirm' },
        cancelLabel: { type: String, default: 'Cancel' },
        variant: { type: String, default: 'primary' },
        mode: { type: String, default: 'apply' },
        loadError: { type: String, default: 'The details of these linked shifts could not be loaded. Please try again.' },
        noDialog: { type: String, default: 'These shifts are taken together. Open the shift page to see them all.' },
        detailUrl: String,
        detailLabel: { type: String, default: 'Open shift page' },
        manageUrl: String,
        manageLabel: { type: String, default: 'Manage' },
    };

    connect() {
        this.confirmed = false;
        this.modal = null;
        this.element_ = null;
        this.gone = false;
        this.onSubmit = this.onSubmit.bind(this);
        this.element.addEventListener('submit', this.onSubmit);
    }

    disconnect() {
        this.gone = true;
        this.element.removeEventListener('submit', this.onSubmit);
        this.teardown();
    }

    /** Opens the same dialog read-only, from an info link outside the submit flow. */
    info(event) {
        event.preventDefault();

        return this.open({ readOnly: true });
    }

    /**
     * Opens the dialog with a working confirm button, from a link rather than a submit. The browse
     * card's only control is a link to the shift page, so there is no submit event to intercept;
     * without JavaScript that link simply navigates there.
     */
    trigger(event) {
        event.preventDefault();

        return this.open({ readOnly: false });
    }

    /**
     * Returns the pending dialog so a caller can await it. Without that the fetch keeps running
     * after a Turbo navigation has replaced the page, and finishes against a document that no longer
     * holds this form.
     */
    onSubmit(event) {
        if (this.confirmed) {
            this.confirmed = false;
            return undefined;
        }

        event.preventDefault();
        this.submitter = event.submitter || null;

        return this.open({ readOnly: false });
    }

    async open({ readOnly }) {
        let html;
        try {
            const url = new URL(this.urlValue, window.location.origin);
            url.searchParams.set('mode', this.modeValue);
            const selected = this.element.querySelector('[name="volunteer_type"]');
            if (selected && selected.value) {
                url.searchParams.set('volunteer_type', selected.value);
            }

            const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok) {
                throw new Error(String(response.status));
            }
            html = await response.text();
        } catch {
            // The dialog is what explains the commitment, so a failed fetch must not let the
            // application through unexplained.
            await alertModal(this.loadErrorValue);
            return;
        }

        // Navigated away while the body was in flight: there is nothing left to attach to, and
        // building a dialog on a replaced document would leave an orphan overlay.
        if (this.gone) {
            return;
        }

        // Bootstrap's JS is what renders the dialog. Without it there is no way to show this, and
        // submitting blind would sign the volunteer up for shifts they were never shown.
        if (!window.bootstrap || !window.bootstrap.Modal) {
            await alertModal(this.noDialogValue);
            return;
        }

        this.teardown();
        this.element_ = this.build(html, readOnly);
        document.body.appendChild(this.element_);

        this.element_.addEventListener('hidden.bs.modal', () => this.teardown());
        // The dialog is appended to <body>, outside this controller's element, so Stimulus actions
        // inside it would never fire. Its listeners are bound here and torn down with the node.
        this.element_.addEventListener('change', () => this.syncConfirmState());
        if (!readOnly) {
            this.element_
                .querySelector('[data-role="confirm"]')
                .addEventListener('click', () => this.accept());
        }

        this.modal = window.bootstrap.Modal.getOrCreateInstance(this.element_);
        this.syncConfirmState();
        this.modal.show();
    }

    syncConfirmState() {
        if (!this.element_) {
            return;
        }
        const button = this.element_.querySelector('[data-role="confirm"]');
        if (!button) {
            return;
        }

        // Cancelling is always permitted from here; whether it is allowed at all is the server's
        // decision, and the applicable flag describes applying, not dropping.
        if (this.modeValue === 'cancel') {
            button.disabled = false;
            return;
        }

        const meta = this.element_.querySelector('[data-shift-group-meta]');
        const applicable = meta ? meta.dataset.applicable === '1' : false;
        const choicesMade = Array.from(this.element_.querySelectorAll('select[name^="group_type"]'))
            .every((select) => select.value !== '');
        const acknowledge = this.element_.querySelector('[name="acknowledge_hours"]');
        const acknowledged = !acknowledge || acknowledge.checked;

        button.disabled = !(applicable && choicesMade && acknowledged);
    }

    /**
     * Moves what the volunteer chose in the dialog onto the real form and submits it. The dialog's
     * fields live outside the form element, so they are copied across as hidden inputs rather than
     * relying on the browser to associate them.
     *
     * A card the volunteer can only read is hosted on a plain element instead of a form: there is
     * nothing to submit, and the read-only dialog offers no confirm button to reach this from.
     */
    accept() {
        if (typeof this.element.requestSubmit !== 'function' && typeof this.element.submit !== 'function') {
            this.modal.hide();
            return;
        }

        this.element.querySelectorAll('[data-shift-group-field]').forEach((node) => node.remove());

        this.element_
            .querySelectorAll('[name^="group_type"], [name="acknowledge_hours"], [name="comment"]')
            .forEach((field) => {
                if (field.type === 'checkbox' && !field.checked) {
                    return;
                }
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = field.name;
                hidden.value = field.value;
                hidden.setAttribute('data-shift-group-field', '');
                this.element.appendChild(hidden);
            });

        this.confirmed = true;
        const submitter = this.submitter;
        this.modal.hide();

        if (typeof this.element.requestSubmit === 'function') {
            this.element.requestSubmit(submitter || undefined);
        } else {
            this.element.submit();
        }
    }

    build(bodyHtml, readOnly) {
        const el = document.createElement('div');
        el.className = 'modal fade';
        el.tabIndex = -1;
        el.setAttribute('aria-hidden', 'true');
        el.innerHTML =
            '<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">' +
            '<div class="modal-content">' +
            '<div class="modal-header"><h5 class="modal-title"></h5>' +
            '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>' +
            '<div class="modal-body"></div>' +
            '<div class="modal-footer">' +
            '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal" data-role="cancel"></button>' +
            '<span data-role="links"></span>' +
            (readOnly ? '' : '<button type="button" class="btn" data-role="confirm"></button>') +
            '</div></div></div>';

        el.querySelector('.modal-title').textContent = this.titleValue;
        this.addLink(el, this.detailUrlValue, this.detailLabelValue);
        this.addLink(el, this.manageUrlValue, this.manageLabelValue);
        // The body is our own server-rendered template, so it is inserted as markup; nothing from the
        // volunteer reaches it unescaped, Twig having escaped it already.
        el.querySelector('.modal-body').innerHTML = bodyHtml;
        el.querySelector('[data-role="cancel"]').textContent = readOnly
            ? this.confirmLabelValue
            : this.cancelLabelValue;

        const confirm = el.querySelector('[data-role="confirm"]');
        if (confirm) {
            confirm.classList.add('btn-' + this.variantValue);
            confirm.textContent = this.confirmLabelValue;
        }

        return el;
    }

    /** A footer link, skipped when the server left its url empty because the viewer may not follow it. */
    addLink(el, url, label) {
        if (!url) {
            return;
        }

        const link = document.createElement('a');
        link.className = 'btn btn-outline-secondary';
        link.href = url;
        link.textContent = label;
        el.querySelector('[data-role="links"]').appendChild(link);
    }

    teardown() {
        if (this.modal) {
            this.modal.dispose();
            this.modal = null;
        }
        if (this.element_) {
            this.element_.remove();
            this.element_ = null;
        }
        // Bootstrap leaves the backdrop behind when a modal is torn down mid-transition, which on a
        // Turbo navigation locks the next page behind an invisible overlay.
        document.querySelectorAll('.modal-backdrop').forEach((node) => node.remove());
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');
        document.body.style.removeProperty('overflow');
    }
}
