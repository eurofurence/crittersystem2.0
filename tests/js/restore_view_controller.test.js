import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import { Application } from '@hotwired/stimulus';
import RestoreViewController from '../../assets/controllers/restore_view_controller.js';

/*
 * The info desk hands out goodies from a long page whose every action redirects back to it. Losing
 * the scroll position and the open tab on each hand-out is the whole reason bulk selection was
 * asked for, so this protects the three things that have to survive a post: the window scroll, the
 * scroll inside the panel the operator was reading, and which tab was open.
 *
 * The stash must also expire and be used only once, otherwise arriving at the page by ordinary
 * navigation would drop the operator wherever a previous visit happened to end.
 *
 * Each test uses a storage key of its own: happy-dom does not run the disconnect that a removed
 * element gets in a browser, so a controller from an earlier test would otherwise keep writing to
 * the key this one reads.
 */

let application;
let shown;
let key;
let counter = 0;

function markup() {
    return `
    <div data-controller="restore-view" data-restore-view-key-value="${key}">
        <a href="#pane-open" data-bs-toggle="tab" class="active">Open</a>
        <a href="#pane-history" data-bs-toggle="tab">History</a>
        <div class="deck-scroll" id="panel-a"></div>
        <div class="deck-scroll" id="panel-b"></div>
        <form id="give"><button>Give</button></form>
    </div>`;
}

async function start() {
    document.body.innerHTML = markup();
    application = Application.start();
    application.register('restore-view', RestoreViewController);
    await new Promise((resolve) => setTimeout(resolve, 0));
    await new Promise((resolve) => requestAnimationFrame(resolve));
}

async function stop() {
    application?.stop();
    application = null;
    document.body.innerHTML = '';
}

function scrollTo(y) {
    Object.defineProperty(window, 'scrollY', { value: y, configurable: true, writable: true });
}

const submitAForm = () => document.getElementById('give').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
const stash = () => window.sessionStorage.getItem(`restore-view:${key}`);

beforeEach(() => {
    shown = [];
    key = `desk-${++counter}`;
    window.sessionStorage.clear();
    window.scrollTo = vi.fn();
    window.bootstrap = {
        Tab: {
            getOrCreateInstance: (element) => ({ show: () => shown.push(element.getAttribute('href')) }),
        },
    };
    scrollTo(0);
});

afterEach(async () => {
    await stop();
    delete window.bootstrap;
});

describe('restore view', () => {
    it('puts back the window scroll, the panel scroll and the open tab after a post', async () => {
        await start();

        document.getElementById('panel-a').scrollTop = 120;
        document.getElementById('panel-b').scrollTop = 40;
        document.querySelector('[href="#pane-open"]').classList.remove('active');
        document.querySelector('[href="#pane-history"]').classList.add('active');
        scrollTo(640);
        submitAForm();

        await stop();
        scrollTo(0);
        await start();

        expect(window.scrollTo).toHaveBeenCalledWith(0, 640);
        expect(document.getElementById('panel-a').scrollTop).toBe(120);
        expect(document.getElementById('panel-b').scrollTop).toBe(40);
        expect(shown).toEqual(['#pane-history']);
    });

    it('restores only once, so arriving at the page again starts at the top', async () => {
        await start();
        scrollTo(300);
        submitAForm();

        await stop();
        await start();
        window.scrollTo.mockClear();
        shown = [];

        await stop();
        await start();

        expect(window.scrollTo).not.toHaveBeenCalled();
        expect(shown).toEqual([]);
    });

    it('ignores a stash left behind by an earlier visit', async () => {
        await start();
        scrollTo(300);
        submitAForm();
        await stop();

        const stale = JSON.parse(stash());
        stale.at -= 60000;
        window.sessionStorage.setItem(`restore-view:${key}`, JSON.stringify(stale));

        await start();

        expect(window.scrollTo).not.toHaveBeenCalled();
    });

    /*
     * The listener lives on the document, so a controller that does not release it keeps stashing
     * scroll positions for pages it no longer owns once Turbo has swapped the body.
     */
    it('releases its document listener on disconnect', async () => {
        await start();
        const element = document.querySelector('[data-controller="restore-view"]');
        application.getControllerForElementAndIdentifier(element, 'restore-view').disconnect();

        scrollTo(900);
        submitAForm();

        expect(stash()).toBeNull();
    });
});
