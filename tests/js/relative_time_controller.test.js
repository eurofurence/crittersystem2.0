import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { Application } from '@hotwired/stimulus';
import RelativeTimeController from '../../assets/controllers/relative_time_controller.js';

/*
 * The clock labels on the operations board.
 *
 * Two properties matter and neither is visible from PHP. The label must keep advancing on its own
 * between renders, and the timer behind it must die with the element - the board is open for hours
 * and Turbo replaces the body without tearing the document down, so a timer that outlives its
 * controller accumulates one more copy on every visit and never stops.
 */

let application;

function mount(html) {
    document.body.innerHTML = html;
    application = Application.start();
    application.register('relative-time', RelativeTimeController);

    return new Promise((resolve) => setTimeout(resolve, 0));
}

beforeEach(() => {
    // shouldAdvanceTime keeps the awaits in mount() resolving: Stimulus connects on a real task, so
    // a frozen clock would hang every test here before the controller ever ran.
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.setSystemTime(new Date('2026-08-19T12:00:00Z'));
    document.documentElement.lang = 'en';
});

afterEach(() => {
    application?.stop();
    document.body.innerHTML = '';
    vi.useRealTimers();
    vi.restoreAllMocks();
});

describe('relative_time_controller', () => {
    it('rewrites a future instant as a countdown', async () => {
        await mount(
            '<time datetime="2026-08-19T12:14:00Z" data-controller="relative-time"'
            + ' data-relative-time-mode-value="countdown">14:00</time>'
        );

        expect(document.querySelector('time').textContent).toMatch(/14 minutes/);
    });

    it('rewrites a past instant as elapsed time', async () => {
        await mount(
            '<time datetime="2026-08-19T11:20:00Z" data-controller="relative-time"'
            + ' data-relative-time-mode-value="ago">11:20</time>'
        );

        expect(document.querySelector('time').textContent).toMatch(/40 minutes ago/);
    });

    it('renders "since" as a compact duration, which reads faster at a distance', async () => {
        await mount(
            '<time datetime="2026-08-19T03:47:00Z" data-controller="relative-time"'
            + ' data-relative-time-mode-value="since">03:47</time>'
        );

        expect(document.querySelector('time').textContent).toBe('8h 13m');
    });

    it('keeps the label advancing between renders', async () => {
        await mount(
            '<time datetime="2026-08-19T03:47:00Z" data-controller="relative-time"'
            + ' data-relative-time-mode-value="since">03:47</time>'
        );

        vi.advanceTimersByTime(120000);

        expect(document.querySelector('time').textContent).toBe('8h 15m');
    });

    /* The absolute instant stays reachable, so the smoothed label never hides the real time. */
    it('keeps the server-rendered time as the title', async () => {
        await mount(
            '<time datetime="2026-08-19T03:47:00Z" data-controller="relative-time"'
            + ' data-relative-time-mode-value="since">03:47</time>'
        );

        expect(document.querySelector('time').title).toBe('03:47');
    });

    /*
     * The point of the controller owning its timer. Asserted by behaviour rather than by spying on
     * clearInterval, because what must not happen is the label still being written after the element
     * has left the page - which is exactly what an orphaned interval does.
     */
    it('stops updating once the element leaves the page', async () => {
        await mount(
            '<time datetime="2026-08-19T03:47:00Z" data-controller="relative-time"'
            + ' data-relative-time-mode-value="since">03:47</time>'
        );

        const element = document.querySelector('time');
        expect(element.textContent).toBe('8h 13m');

        element.remove();
        await new Promise((resolve) => setTimeout(resolve, 0));
        vi.advanceTimersByTime(600000);

        expect(element.textContent).toBe('8h 13m');
    });

    it('leaves an unparseable instant alone rather than printing NaN', async () => {
        await mount('<time datetime="not-a-date" data-controller="relative-time">soon</time>');

        vi.advanceTimersByTime(5000);

        expect(document.querySelector('time').textContent).toBe('soon');
    });
});
