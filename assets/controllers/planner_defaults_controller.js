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

    broadcast(detail) {
        window.dispatchEvent(new CustomEvent('planner:paint-defaults', { detail }));
    }
}
