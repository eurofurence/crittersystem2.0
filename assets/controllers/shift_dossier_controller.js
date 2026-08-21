import { Controller } from '@hotwired/stimulus';
import { alertModal } from '../js/modal.js';

/*
 * Opens the full detail of one shift in a dialog, so an operator reading a list does not lose the
 * page they are working on.
 *
 *   <button data-controller="shift-dossier"
 *           data-action="shift-dossier#open"
 *           data-shift-dossier-url-value="{{ path('app_shift_info', {id: shift.uuid}) }}"
 *           data-shift-dossier-title-value="{{ shift.title }}">
 *
 * The body is fetched on every open and never built here from a data attribute. What the dossier
 * shows depends on the viewer's privileges and on the shift's own department, and that decision
 * belongs to the server; an attribute rendered into the list would also carry the privileged half
 * into the markup of everyone who can read the page source.
 *
 * The trigger stays a working link without JavaScript: it points at the shift page, which renders
 * the same dossier.
 */
export default class extends Controller {
    static values = {
        url: String,
        title: { type: String, default: 'Shift details' },
        closeLabel: { type: String, default: 'Close' },
        loadError: { type: String, default: 'The shift details could not be loaded. Please try again.' },
    };

    connect() {
        this.modal = null;
        this.element_ = null;
        this.gone = false;
    }

    disconnect() {
        this.gone = true;
        this.teardown();
    }

    async open(event) {
        if (event) {
            event.preventDefault();
        }

        let html;
        try {
            const response = await fetch(this.urlValue, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok) {
                throw new Error(String(response.status));
            }
            html = await response.text();
        } catch {
            await alertModal(this.loadErrorValue);
            return;
        }

        // Navigated away while the body was in flight: building a dialog on a replaced document
        // would leave an orphan overlay with nothing to close it.
        if (this.gone) {
            return;
        }

        // Without Bootstrap's JS there is no dialog to show. The trigger is a link to the shift
        // page, so letting it navigate is the honest fallback.
        if (!window.bootstrap || !window.bootstrap.Modal) {
            window.location.assign(this.urlValue.replace(/\/info$/, ''));
            return;
        }

        this.teardown();
        this.element_ = this.build(html);
        document.body.appendChild(this.element_);
        this.element_.addEventListener('hidden.bs.modal', () => this.teardown());

        this.modal = window.bootstrap.Modal.getOrCreateInstance(this.element_);
        this.modal.show();
    }

    build(bodyHtml) {
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
            '</div></div></div>';

        el.querySelector('.modal-title').textContent = this.titleValue;
        el.querySelector('[data-role="cancel"]').textContent = this.closeLabelValue;
        // Our own server-rendered template, already escaped by Twig.
        el.querySelector('.modal-body').innerHTML = bodyHtml;

        return el;
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
