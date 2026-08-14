import { Controller } from '@hotwired/stimulus';

/*
 * Turns picking a department in the rail's combobox into a navigation.
 *
 * The kit's combobox is a form control: it updates a hidden input and announces the change, and
 * nothing more. The board has no form to submit - changing department reloads the whole board for a
 * new scope, including re-subscribing its live region - so the selection has to become a URL.
 *
 * The template hands over the URL it is already showing plus the uuid inside it, and the swap is a
 * substitution rather than a URL built here. Route shapes belong to the router: building one in
 * JavaScript means a change to the board's routing silently stops the rail working.
 */
export default class extends Controller {
    static values = {
        url: String,
        current: String,
    };

    go(event) {
        const selected = event.detail?.value;
        if (!selected || selected === this.currentValue) {
            return;
        }

        const target = this.urlValue.replace(this.currentValue, selected);

        // Turbo when it is there, a plain assignment when it is not: this must work even if the
        // board is open on a machine where the JavaScript bundle only partly loaded.
        if (window.Turbo) {
            window.Turbo.visit(target);
        } else {
            window.location.assign(target);
        }
    }
}
