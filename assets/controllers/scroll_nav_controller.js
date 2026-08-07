import { Controller } from '@hotwired/stimulus';

/*
 * Arrows for a region that scrolls sideways.
 *
 * A wide grid that scrolls horizontally does not look scrollable: a significant number of managers
 * never discovered that the planner had more days than the ones on screen. The arrows are the
 * affordance, and each one is hidden as soon as there is nothing left to scroll in its direction, so
 * they also read as "you are at the end".
 *
 * Deliberately generic - it knows about a scroll container and two buttons, nothing about what is
 * inside - because every wide grid in the app needs the same thing.
 */
export default class extends Controller {
    static targets = ['scroller', 'prev', 'next'];

    /** How far one press scrolls. Zero means most of a screenful, which suits any column width. */
    static values = { step: { type: Number, default: 0 } };

    connect() {
        this.onScroll = () => this.update();
        this.observer = new ResizeObserver(() => this.update());
        this.update();
    }

    disconnect() {
        this.observer?.disconnect();
        this.observer = null;
    }

    /**
     * The scroll container is replaced wholesale whenever the region refreshes, so the listeners and
     * the observer are attached per element rather than once in connect().
     */
    scrollerTargetConnected(element) {
        element.addEventListener('scroll', this.onScroll, { passive: true });
        this.observer?.observe(element);
        this.update();
    }

    scrollerTargetDisconnected(element) {
        element.removeEventListener('scroll', this.onScroll);
        this.observer?.unobserve(element);
    }

    prev() {
        this.scrollBy(-1);
    }

    next() {
        this.scrollBy(1);
    }

    scrollBy(direction) {
        if (!this.hasScrollerTarget) {
            return;
        }
        const scroller = this.scrollerTarget;
        const step = this.stepValue > 0 ? this.stepValue : Math.round(scroller.clientWidth * 0.8);
        scroller.scrollBy({ left: direction * step, behavior: 'smooth' });
    }

    update() {
        if (!this.hasScrollerTarget) {
            return;
        }
        const scroller = this.scrollerTarget;
        // A fractional scroll width is normal at non-integer zoom levels, so the end is approached
        // rather than reached exactly.
        const atEnd = scroller.scrollLeft >= scroller.scrollWidth - scroller.clientWidth - 1;

        this.toggle(this.hasPrevTarget ? this.prevTarget : null, scroller.scrollLeft > 1);
        this.toggle(this.hasNextTarget ? this.nextTarget : null, !atEnd);
    }

    toggle(button, visible) {
        if (button === null) {
            return;
        }
        button.hidden = !visible;
    }
}
