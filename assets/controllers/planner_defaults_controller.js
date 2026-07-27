import { Controller } from '@hotwired/stimulus';

/*
 * Toolbar paint defaults: broadcasts the audience/task a freshly painted shift
 * should use, so the planner controller picks them up.
 */
export default class extends Controller {
    audience(event) {
        this.broadcast({ audience: event.target.value });
    }

    task(event) {
        this.broadcast({ task: event.target.value });
    }

    // 'paint' makes every drag create a shift, including on top of an existing one; 'select' keeps
    // drags moving and resizing. Without the mode there is no way to start a parallel shift, because
    // a drag beginning on a block is always read as a move.
    mode(event) {
        this.broadcast({ mode: event.target.value });
    }

    broadcast(detail) {
        window.dispatchEvent(new CustomEvent('planner:paint-defaults', { detail }));
    }
}
