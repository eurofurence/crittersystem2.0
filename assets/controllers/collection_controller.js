import { Controller } from '@hotwired/stimulus';

/*
 * Generic add/remove for a Symfony CollectionType (allow_add / allow_delete).
 * The wrapper carries data-prototype; new rows replace __name__ with a counter.
 */
export default class extends Controller {
    static values = { index: Number };

    connect() {
        if (!this.hasIndexValue) {
            this.indexValue = this.container().querySelectorAll('[data-collection-item]').length;
        }
    }

    add(event) {
        event.preventDefault();
        const prototype = this.element.dataset.prototype;
        if (!prototype) {
            return;
        }
        const html = prototype.replace(/__name__/g, this.indexValue);
        this.indexValue += 1;

        const item = document.createElement('div');
        item.setAttribute('data-collection-item', '');
        item.className = 'border rounded p-2 mb-2';
        item.innerHTML = html
            + '<button type="button" class="btn btn-sm btn-outline-danger mt-1" data-action="collection#remove">Remove</button>';
        this.container().appendChild(item);
    }

    remove(event) {
        event.preventDefault();
        event.target.closest('[data-collection-item]')?.remove();
    }

    container() {
        return this.element.querySelector('[data-collection-container]');
    }
}
