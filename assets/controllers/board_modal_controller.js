import { Controller } from '@hotwired/stimulus';

/*
 * Host for the board's overflow dialogs.
 *
 * Sits on the Turbo Frame that the "show all" links load into, which lives outside the live region
 * on purpose: the board replaces the view's markup whenever the department's data changes, and a
 * dialog rendered inside it would vanish from under whoever opened it.
 *
 * Opening, Escape, click-outside and focus trapping all belong to the kit's dialog component and to
 * the native `<dialog>` under it. All that is left here is emptying the frame once the dialog has
 * closed, so the next open re-fetches rather than showing a list that went stale while it was shut.
 */
export default class extends Controller {
    clear() {
        this.element.innerHTML = '';
    }
}
